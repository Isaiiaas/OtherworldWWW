#!/usr/bin/env node
/**
 * Prepare QA work per tile.
 *
 * Reads map-locations.json + the tile index, assigns each pin to the
 * tile whose center is closest to it (tile overlap is fine for vision
 * but we want each pin verified exactly once), and writes one
 * /tmp/otherworld_map_tiles/qa_work_rR_cC.json per tile with the pins
 * that tile's QA agent should verify.
 *
 * Each per-tile work file also includes the pin's tile-local position
 * so the agent doesn't have to do the coordinate math.
 */
const fs = require("fs");
const path = require("path");

const ROOT      = path.resolve(__dirname, "..");
const TILES_DIR = "/tmp/otherworld_map_tiles";
const LOC_PATH  = path.join(ROOT, "map-locations.json");

const tileIndex = JSON.parse(fs.readFileSync(path.join(TILES_DIR, "index.json"), "utf8"));
const loc       = JSON.parse(fs.readFileSync(LOC_PATH, "utf8"));

function tileCenter(t) {
  const b = t.normBounds;
  return [(b.x0 + b.x1) / 2, (b.y0 + b.y1) / 2];
}

function pickTile(pin, tiles) {
  // The tile whose center is closest to the pin (Euclidean distance in
  // normalized coords).
  let best = null, bestD = Infinity;
  for (const t of tiles) {
    const [cx, cy] = tileCenter(t);
    const d = Math.hypot(pin.x - cx, pin.y - cy);
    if (d < bestD) { bestD = d; best = t; }
  }
  return best;
}

function tileLocal(pin, tile) {
  const b = tile.normBounds;
  return {
    tileX: (pin.x - b.x0) / (b.x1 - b.x0),
    tileY: (pin.y - b.y0) / (b.y1 - b.y0),
  };
}

// Clean any prior QA files so stale results don't get merged in.
for (const f of fs.readdirSync(TILES_DIR)) {
  if (/^qa_(work|result)_r\d+_c\d+\.json$/.test(f)) {
    fs.unlinkSync(path.join(TILES_DIR, f));
  }
}

const byTile = new Map();
for (const t of tileIndex.tiles) byTile.set(`${t.row}_${t.col}`, []);

let assigned = 0;
for (const pin of loc.pins) {
  const t = pickTile(pin, tileIndex.tiles);
  if (!t) continue;
  const local = tileLocal(pin, t);
  // Skip pins that land far outside the tile (shouldn't happen since
  // tiles cover the full map, but be defensive).
  if (local.tileX < -0.05 || local.tileX > 1.05 || local.tileY < -0.05 || local.tileY > 1.05) continue;
  byTile.get(`${t.row}_${t.col}`).push({
    name: pin.name,
    type: pin.type,
    expectedTileX: Math.round(local.tileX * 1000) / 1000,
    expectedTileY: Math.round(local.tileY * 1000) / 1000,
    currentConfidence: pin.confidence ?? null,
    source: pin.source || (pin.matchedText ? "text" : "manual"),
  });
  assigned++;
}

for (const t of tileIndex.tiles) {
  const key = `${t.row}_${t.col}`;
  const pins = byTile.get(key);
  fs.writeFileSync(
    path.join(TILES_DIR, `qa_work_${key}.json`),
    JSON.stringify({
      tileFile: t.file,
      tileRow: t.row,
      tileCol: t.col,
      tileBoundsInMap: t.normBounds,
      pinsToVerify: pins,
    }, null, 2)
  );
}

console.error(`Assigned ${assigned} pins across ${tileIndex.tiles.length} tiles:`);
for (const t of tileIndex.tiles) {
  const n = byTile.get(`${t.row}_${t.col}`).length;
  if (n > 0) console.error(`  r${t.row}_c${t.col}: ${n}`);
}
const empty = tileIndex.tiles.filter(t => byTile.get(`${t.row}_${t.col}`).length === 0);
if (empty.length) console.error(`  (${empty.length} tiles have no pins)`);
