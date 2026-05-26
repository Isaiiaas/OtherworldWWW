#!/usr/bin/env node
/**
 * Merge QA agent verdicts back into map-locations.json.
 *
 * Each QA agent wrote qa_result_rR_cC.json with one verdict per pin:
 *   - "correct"  → bump confidence, mark `verified: true`
 *   - "adjust"   → move pin to suggested (tileX, tileY), mark `verified: true`,
 *                  but only if the position actually changed
 *   - "wrong"    → mark `qaWrong: true` and add `qaEvidence`. We DON'T
 *                  delete the pin — the human user gets the final say
 *                  via the annotator. (Deleting would silently lose
 *                  agent work the user might want to recover.)
 *
 * Confidence math when a pin is verified `correct`:
 *   new = max(old, agentVerifyConf) — verification can only raise it.
 *
 * Optional promotion: with --auto-confirm, any pin whose final
 * confidence is ≥ AUTO_CONFIRM_THRESHOLD AND was verified `correct` by
 * a different tile's QA agent is promoted to confirmed=true.
 */
const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

const ROOT      = path.resolve(__dirname, "..");
const TILES_DIR = "/tmp/otherworld_map_tiles";
const LOC_PATH  = path.join(ROOT, "map-locations.json");
const DATA_PATH = path.join(ROOT, "map-data.js");
const MAP_PNG   = path.join(ROOT, "map.png");

const AUTO_CONFIRM_THRESHOLD = 0.85;
const AUTO_CONFIRM = process.argv.includes("--auto-confirm");

const tileIndex = JSON.parse(fs.readFileSync(path.join(TILES_DIR, "index.json"), "utf8"));
const tileByKey = new Map(tileIndex.tiles.map(t => [`${t.row}_${t.col}`, t]));

const existing = JSON.parse(fs.readFileSync(LOC_PATH, "utf8"));
const pinByName = new Map(existing.pins.map(p => [p.name, p]));

// Collect verdicts (one per pin, by tile).
const verdicts = new Map(); // name -> { verdict, x?, y?, confidence, evidence, fromTile }
let totalVerdicts = 0, unknownNames = 0;

for (const file of fs.readdirSync(TILES_DIR)) {
  const m = file.match(/^qa_result_r(\d+)_c(\d+)\.json$/);
  if (!m) continue;
  const tile = tileByKey.get(`${m[1]}_${m[2]}`);
  if (!tile) continue;
  let arr;
  try {
    arr = JSON.parse(fs.readFileSync(path.join(TILES_DIR, file), "utf8"));
  } catch (e) {
    console.error(`Could not parse ${file}: ${e.message}`);
    continue;
  }
  if (!Array.isArray(arr)) continue;
  for (const v of arr) {
    totalVerdicts++;
    if (!v || typeof v.name !== "string") continue;
    if (!pinByName.has(v.name)) { unknownNames++; continue; }
    const verdict = String(v.verdict || "").toLowerCase();
    if (!["correct", "adjust", "wrong"].includes(verdict)) continue;

    const entry = {
      verdict,
      confidence: clamp01(Number(v.confidence) || 0.5),
      evidence: typeof v.evidence === "string" ? v.evidence : "",
      fromTile: `r${m[1]}_c${m[2]}`,
    };
    if (verdict === "adjust") {
      const b = tile.normBounds;
      entry.x = clamp01(b.x0 + clamp01(Number(v.tileX)) * (b.x1 - b.x0));
      entry.y = clamp01(b.y0 + clamp01(Number(v.tileY)) * (b.y1 - b.y0));
    }
    // If the same pin somehow has multiple verdicts (rare — pins are
    // assigned to one tile), keep the highest-confidence one.
    const prev = verdicts.get(v.name);
    if (!prev || entry.confidence > prev.confidence) verdicts.set(v.name, entry);
  }
}

function clamp01(n) {
  if (!isFinite(n)) return 0.5;
  if (n < 0) return 0;
  if (n > 1) return 1;
  return n;
}

const stats = {
  correct: 0, adjust: 0, wrong: 0,
  noVerdict: 0,
  promoted: 0,
};

const updated = [];
for (const p of existing.pins) {
  const v = verdicts.get(p.name);
  if (!v) {
    stats.noVerdict++;
    updated.push(p);
    continue;
  }
  // Build the new pin record.
  const next = { ...p };
  delete next.qaWrong;
  delete next.qaEvidence;
  next.qaVerdict = v.verdict;
  next.qaEvidence = v.evidence || undefined;
  next.qaConfidence = Math.round(v.confidence * 100) / 100;

  if (v.verdict === "correct") {
    stats.correct++;
    next.verified = true;
    next.confidence = Math.max(p.confidence || 0, v.confidence);
    next.confidence = Math.round(next.confidence * 100) / 100;
  } else if (v.verdict === "adjust") {
    stats.adjust++;
    next.verified = true;
    next.x = Math.round(v.x * 10000) / 10000;
    next.y = Math.round(v.y * 10000) / 10000;
    // The QA agent could see the label clearly enough to reposition,
    // so trust its confidence as the new baseline.
    next.confidence = Math.round(v.confidence * 100) / 100;
  } else if (v.verdict === "wrong") {
    stats.wrong++;
    next.verified = false;
    next.qaWrong = true;
    // Knock confidence down so the annotator surfaces it for review.
    next.confidence = Math.min(p.confidence || 1, 0.3);
  }

  // Optional auto-confirm: pin was independently verified correct by a
  // different agent than the one that placed it, and confidence is high.
  if (
    AUTO_CONFIRM &&
    !next.confirmed &&
    next.verified &&
    v.verdict === "correct" &&
    next.confidence >= AUTO_CONFIRM_THRESHOLD &&
    (!p.source || !p.source.includes(v.fromTile))   // different tile = independent
  ) {
    next.confirmed = true;
    stats.promoted++;
  }

  // Drop the undefined keys to keep the JSON tidy.
  for (const k of Object.keys(next)) if (next[k] === undefined) delete next[k];
  updated.push(next);
}

// Stable order by type then name.
const typeOrder = { camp: 0, sound_stage: 1, art_installation: 2 };
updated.sort((a, b) => {
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
  pins: updated,
};

fs.writeFileSync(LOC_PATH, JSON.stringify(output, null, 2));
fs.writeFileSync(DATA_PATH, `window.OTHERWORLD_MAP = ${JSON.stringify(output)};\n`);

console.error(`QA verdicts processed: ${totalVerdicts}`);
console.error(`  unknown names (skipped): ${unknownNames}`);
console.error(`Per-pin verdict counts:`);
console.error(`  correct:    ${stats.correct}`);
console.error(`  adjust:     ${stats.adjust}`);
console.error(`  wrong:      ${stats.wrong}`);
console.error(`  no verdict: ${stats.noVerdict} (pins QA didn't touch)`);
if (AUTO_CONFIRM) {
  console.error(`Auto-confirmed: ${stats.promoted} pins (independent verify, conf ≥ ${AUTO_CONFIRM_THRESHOLD})`);
} else {
  console.error(`Re-run with --auto-confirm to promote independently-verified pins to confirmed=true.`);
}
console.error(`Final pin counts:`);
console.error(`  total:     ${updated.length}`);
console.error(`  confirmed: ${updated.filter(p => p.confirmed).length}`);
console.error(`  verified:  ${updated.filter(p => p.verified).length}`);
console.error(`  qaWrong:   ${updated.filter(p => p.qaWrong).length}`);
