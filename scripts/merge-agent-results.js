#!/usr/bin/env node
/**
 * Merge sub-agent results from /tmp/otherworld_map_tiles/ into
 * map-locations.json.
 *
 * Each agent wrote result_rR_cC.json with tile-local coordinates.
 * Here we:
 *   1. Read every result file and the tile index for bounds.
 *   2. Convert tile-local (tileX, tileY) to map-normalized (x, y).
 *   3. Dedupe by entry name: keep the highest-confidence claim, or
 *      average if multiple tiles agreed within 8% (overlap region).
 *   4. Drop any name that doesn't appear in events.json (hallucination).
 *   5. Drop any name already CONFIRMED (so we don't overwrite manual work).
 *   6. Write merged result back to map-locations.json + map-data.js.
 *
 * All agent-suggested pins are stored with confirmed=false so they
 * show as suggestions in the annotator. The user clicks to confirm.
 */
const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

const ROOT      = path.resolve(__dirname, "..");
const TILES_DIR = "/tmp/otherworld_map_tiles";
const LOC_PATH  = path.join(ROOT, "map-locations.json");
const DATA_PATH = path.join(ROOT, "map-data.js");
const EVENTS    = path.join(ROOT, "events.json");
const MAP_PNG   = path.join(ROOT, "map.png");

const AGREEMENT_RADIUS = 0.04; // 4% of map width — if two tiles report a name within this, they agree

const tileIndex = JSON.parse(fs.readFileSync(path.join(TILES_DIR, "index.json"), "utf8"));
const tileByKey = new Map(tileIndex.tiles.map(t => [`${t.row}_${t.col}`, t]));

const events = JSON.parse(fs.readFileSync(EVENTS, "utf8"));
const wantedTypes = new Set(["camp", "sound_stage", "art_installation"]);
const typeByName = new Map();
for (const e of events.entries) {
  if (wantedTypes.has(e.type)) typeByName.set(e.name, e.type);
}

const existing = JSON.parse(fs.readFileSync(LOC_PATH, "utf8"));
const existingByName = new Map(existing.pins.map(p => [p.name, p]));
const confirmedNames = new Set(
  existing.pins.filter(p => p.confirmed).map(p => p.name)
);

// Collect candidate claims from every agent.
const claimsByName = new Map(); // name -> [{x, y, confidence, evidence, source}]
let totalClaims = 0;
let dropped_unknown = 0;
let dropped_confirmed = 0;

for (const file of fs.readdirSync(TILES_DIR)) {
  const m = file.match(/^result_r(\d+)_c(\d+)\.json$/);
  if (!m) continue;
  const tile = tileByKey.get(`${m[1]}_${m[2]}`);
  if (!tile) continue;

  let claims;
  try {
    claims = JSON.parse(fs.readFileSync(path.join(TILES_DIR, file), "utf8"));
  } catch (e) {
    console.error(`Could not parse ${file}: ${e.message}`);
    continue;
  }
  if (!Array.isArray(claims)) continue;

  for (const c of claims) {
    totalClaims++;
    if (!c || typeof c.name !== "string") continue;
    if (!typeByName.has(c.name)) { dropped_unknown++; continue; }
    if (confirmedNames.has(c.name)) { dropped_confirmed++; continue; }

    // tileX/tileY in [0..1] of the tile → map-normalized via tile bounds.
    const tx = clamp01(Number(c.tileX));
    const ty = clamp01(Number(c.tileY));
    const b = tile.normBounds;
    const x = b.x0 + tx * (b.x1 - b.x0);
    const y = b.y0 + ty * (b.y1 - b.y0);
    const conf = clamp01(Number(c.confidence) || 0.5);

    if (!claimsByName.has(c.name)) claimsByName.set(c.name, []);
    claimsByName.get(c.name).push({
      x, y, confidence: conf,
      evidence: typeof c.evidence === "string" ? c.evidence : "",
      source: `r${m[1]}_c${m[2]}`,
    });
  }
}

function clamp01(n) {
  if (!isFinite(n)) return 0.5;
  if (n < 0) return 0;
  if (n > 1) return 1;
  return n;
}

// Resolve duplicates per name.
function resolveClaims(claims) {
  if (claims.length === 1) return claims[0];
  // Find the highest-confidence claim.
  const sorted = claims.slice().sort((a, b) => b.confidence - a.confidence);
  const best = sorted[0];
  // If the second-best is geographically close, average them (the tile
  // overlap caught the same label twice — averaging recovers slightly
  // better positioning).
  const agreeing = sorted.filter(c =>
    Math.hypot(c.x - best.x, c.y - best.y) <= AGREEMENT_RADIUS
  );
  if (agreeing.length >= 2) {
    const ax = agreeing.reduce((s, c) => s + c.x, 0) / agreeing.length;
    const ay = agreeing.reduce((s, c) => s + c.y, 0) / agreeing.length;
    return { ...best, x: ax, y: ay, agreement: agreeing.length };
  }
  return best;
}

const newPins = [];
const conflicts = []; // claims that disagreed geographically
for (const [name, claims] of claimsByName) {
  const resolved = resolveClaims(claims);
  const allOk = claims.every(c =>
    Math.hypot(c.x - resolved.x, c.y - resolved.y) <= AGREEMENT_RADIUS
  );
  if (!allOk) conflicts.push({ name, claims });

  newPins.push({
    name,
    type: typeByName.get(name),
    x: round(resolved.x),
    y: round(resolved.y),
    confidence: Math.round(resolved.confidence * 100) / 100,
    matchedText: resolved.evidence || "",
    source: "agent:" + resolved.source,
    confirmed: false,
  });
}

function round(n) { return Math.round(n * 10000) / 10000; }

// Merge with existing pins:
//   - Keep all confirmed existing pins.
//   - For each new agent suggestion, replace any unconfirmed existing pin
//     with the same name (assumes agent's vision is at least as good as
//     the text-clustering fuzzy match for these names).
const merged = [];
const seenNames = new Set();
for (const p of existing.pins) {
  if (p.confirmed) {
    merged.push(p);
    seenNames.add(p.name);
  }
}
for (const p of newPins) {
  if (seenNames.has(p.name)) continue;
  merged.push(p);
  seenNames.add(p.name);
}
// Carry over remaining unconfirmed pins whose name didn't get an agent claim.
for (const p of existing.pins) {
  if (p.confirmed || seenNames.has(p.name)) continue;
  merged.push(p);
  seenNames.add(p.name);
}

// Stable order.
const typeOrder = { camp: 0, sound_stage: 1, art_installation: 2 };
merged.sort((a, b) => {
  const ia = typeOrder[a.type] ?? 9, ib = typeOrder[b.type] ?? 9;
  if (ia !== ib) return ia - ib;
  return a.name.localeCompare(b.name, undefined, { sensitivity: "base" });
});

const version = fs.existsSync(MAP_PNG)
  ? crypto.createHash("md5").update(fs.readFileSync(MAP_PNG)).digest("hex").slice(0, 12)
  : existing.version || "0";

const output = {
  pageWidth:  existing.pageWidth,
  pageHeight: existing.pageHeight,
  version,
  imagePath:  existing.imagePath || "map.png",
  pins: merged,
};

fs.writeFileSync(LOC_PATH, JSON.stringify(output, null, 2));
fs.writeFileSync(DATA_PATH, `window.OTHERWORLD_MAP = ${JSON.stringify(output)};\n`);

console.error(`Agent claims processed: ${totalClaims}`);
console.error(`  dropped (name not in schedule): ${dropped_unknown}`);
console.error(`  dropped (already confirmed):    ${dropped_confirmed}`);
console.error(`  unique names placed by agents:  ${newPins.length}`);
console.error(`  conflicting positions (>1 tile, diverging): ${conflicts.length}`);
console.error(`Final pin counts:`);
console.error(`  total:     ${merged.length}`);
console.error(`  confirmed: ${merged.filter(p => p.confirmed).length}`);
console.error(`  suggested: ${merged.filter(p => !p.confirmed).length}`);
const byType = merged.reduce((a, p) => { a[p.type] = (a[p.type]||0)+1; return a; }, {});
console.error(`  by type:   ${JSON.stringify(byType)}`);
if (conflicts.length) {
  console.error(`Sample of conflicting claims:`);
  for (const c of conflicts.slice(0, 5)) {
    console.error(`  ${c.name}: ${c.claims.map(x => `(${x.x.toFixed(2)},${x.y.toFixed(2)} c=${x.confidence})`).join(" vs ")}`);
  }
}
