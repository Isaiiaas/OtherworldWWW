#!/usr/bin/env node
/**
 * Crop map.png into a grid of overlapping tiles and write them to
 * /tmp/otherworld_map_tiles/. Tiles overlap by 10% so a label that
 * straddles a tile boundary still appears fully in at least one tile.
 *
 * Also writes /tmp/otherworld_map_tiles/index.json with each tile's
 * normalized 0..1 bounds within the source map — sub-agents return
 * positions in tile-local coords, we map them back to map-global coords
 * using these bounds.
 *
 * Usage: node scripts/crop-map.js [cols=4] [rows=3]
 */
const fs = require("fs");
const path = require("path");
const { PNG } = require("pngjs");

const SRC      = path.resolve(__dirname, "..", "map.png");
const DEST_DIR = "/tmp/otherworld_map_tiles";
const OVERLAP  = 0.10;

const cols = parseInt(process.argv[2], 10) || 4;
const rows = parseInt(process.argv[3], 10) || 3;

if (!fs.existsSync(SRC)) {
  console.error(`Missing ${SRC}. Run \`node parse-map.js\` first.`);
  process.exit(1);
}

fs.mkdirSync(DEST_DIR, { recursive: true });
// Clean any prior run so stale tiles don't confuse the merge step.
for (const f of fs.readdirSync(DEST_DIR)) {
  if (/^tile_/.test(f) || f === "index.json") fs.unlinkSync(path.join(DEST_DIR, f));
}

const src = PNG.sync.read(fs.readFileSync(SRC));
const W = src.width, H = src.height;

// Base (non-overlapping) tile dimensions.
const baseW = W / cols;
const baseH = H / rows;
// Pad on each side so adjacent tiles overlap by OVERLAP fraction.
const padX = Math.round(baseW * OVERLAP);
const padY = Math.round(baseH * OVERLAP);

const tiles = [];
for (let r = 0; r < rows; r++) {
  for (let c = 0; c < cols; c++) {
    const x0 = Math.max(0, Math.round(c * baseW) - padX);
    const y0 = Math.max(0, Math.round(r * baseH) - padY);
    const x1 = Math.min(W, Math.round((c + 1) * baseW) + padX);
    const y1 = Math.min(H, Math.round((r + 1) * baseH) + padY);
    const tw = x1 - x0;
    const th = y1 - y0;

    const tile = new PNG({ width: tw, height: th });
    for (let y = 0; y < th; y++) {
      const srcRow = (y0 + y) * W * 4;
      const dstRow = y * tw * 4;
      tile.data.set(src.data.subarray(srcRow + x0 * 4, srcRow + x1 * 4), dstRow);
    }
    const fname = `tile_r${r}_c${c}.png`;
    fs.writeFileSync(path.join(DEST_DIR, fname), PNG.sync.write(tile));

    tiles.push({
      file: fname,
      row: r, col: c,
      pixelBounds: { x0, y0, x1, y1, width: tw, height: th },
      normBounds:  { x0: x0 / W, y0: y0 / H, x1: x1 / W, y1: y1 / H },
    });
  }
}

const index = {
  source:    path.basename(SRC),
  sourceWidth: W, sourceHeight: H,
  cols, rows,
  overlap: OVERLAP,
  tiles,
};
fs.writeFileSync(path.join(DEST_DIR, "index.json"), JSON.stringify(index, null, 2));

console.error(`Wrote ${tiles.length} tiles to ${DEST_DIR} (cols=${cols} rows=${rows}, overlap=${(OVERLAP*100)|0}%).`);
console.error(`Each tile ~${Math.round(baseW + 2*padX)}x${Math.round(baseH + 2*padY)} px.`);
