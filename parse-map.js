#!/usr/bin/env node
/**
 * Parse the festival map PDF.
 *
 * Outputs:
 *  - map.png           : rendered map image (high-res)
 *  - map-labels.json   : every clustered text label found on the map
 *                        (centroid + text + bbox), useful as hints in
 *                        the annotation UI.
 *  - map-locations.json: best-effort auto-suggested camp/stage/art pins,
 *                        each with a confidence score. The annotation
 *                        UI loads this as a starting point — user drags
 *                        to refine and confirms positions.
 *
 * The map is hand-drawn and labels follow curved paths, so pdftotext
 * returns highly fragmented words (e.g. "CAM", "MPI", "NG" for "CAMPING").
 * Strategy: cluster nearby fragments into "labels", then fuzzy-match
 * each entry name against the labels using normalized-letter overlap.
 */

const fs = require("fs");
const path = require("path");
const crypto = require("crypto");
const { execFileSync } = require("child_process");

const MAP_PDF = path.resolve(__dirname, "map.pdf");
const MAP_PNG = path.resolve(__dirname, "map.png");
const TMP_BBOX = "/tmp/map.bbox.html";
const TMP_RENDER_DIR = "/tmp/map_render";
const EVENTS_JSON = path.resolve(__dirname, "events.json");
const LABELS_OUT = path.resolve(__dirname, "map-labels.json");
const LOCATIONS_OUT = path.resolve(__dirname, "map-locations.json");
const MAP_DATA_JS = path.resolve(__dirname, "map-data.js");

const RENDER_DPI = 200;
const CLUSTER_EPS_PT = 4;        // tight merge of adjacent pdftotext blocks
const MIN_LABEL_LEN = 3;         // drop noise (single chars, punctuation)
const FUZZY_MIN_SCORE = 0.7;     // minimum letter-coverage to accept

// ---------- render ----------

function renderMapImage() {
  fs.mkdirSync(TMP_RENDER_DIR, { recursive: true });
  execFileSync("pdftoppm", [
    "-r", String(RENDER_DPI),
    "-png",
    "-f", "1", "-l", "1",
    MAP_PDF,
    path.join(TMP_RENDER_DIR, "map"),
  ]);
  fs.copyFileSync(path.join(TMP_RENDER_DIR, "map-1.png"), MAP_PNG);
  console.error(`Wrote ${MAP_PNG}`);
}

// ---------- bbox parse ----------

function decodeEntities(s) {
  return s
    .replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&apos;/g, "'");
}

function parseBbox() {
  execFileSync("pdftotext", ["-bbox-layout", MAP_PDF, TMP_BBOX]);
  const html = fs.readFileSync(TMP_BBOX, "utf8");
  const pageM = html.match(/<page width="([0-9.]+)" height="([0-9.]+)">([\s\S]*?)<\/page>/);
  if (!pageM) throw new Error("No page in bbox HTML");
  const pageWidth = +pageM[1];
  const pageHeight = +pageM[2];

  // pdftotext's own <block> grouping is already much closer to "one label"
  // than per-word fragments. Use blocks as the base unit; we may merge
  // tightly-adjacent blocks below.
  const blocks = [];
  const blockRe = /<block xMin="([0-9.]+)" yMin="([0-9.]+)" xMax="([0-9.]+)" yMax="([0-9.]+)">([\s\S]*?)<\/block>/g;
  let m;
  while ((m = blockRe.exec(pageM[3])) !== null) {
    const xMin = +m[1], yMin = +m[2], xMax = +m[3], yMax = +m[4];
    const wordRe = /<word[^>]*>([\s\S]*?)<\/word>/g;
    let w, parts = [];
    while ((w = wordRe.exec(m[5])) !== null) {
      const t = decodeEntities(w[1].replace(/\s+/g, "").trim());
      if (t) parts.push(t);
    }
    const text = parts.join("");
    if (!text) continue;
    blocks.push({
      xMin, yMin, xMax, yMax,
      cx: (xMin + xMax) / 2,
      cy: (yMin + yMax) / 2,
      text,
    });
  }
  return { pageWidth, pageHeight, fragments: blocks };
}

// ---------- clustering ----------

// Union-find for connecting nearby fragments.
function makeUF(n) {
  const p = new Array(n).fill(0).map((_, i) => i);
  function find(x) { while (p[x] !== x) { p[x] = p[p[x]]; x = p[x]; } return x; }
  function union(a, b) { const ra = find(a), rb = find(b); if (ra !== rb) p[ra] = rb; }
  return { find, union };
}

function bboxDistance(a, b) {
  const dx = Math.max(0, Math.max(a.xMin, b.xMin) - Math.min(a.xMax, b.xMax));
  const dy = Math.max(0, Math.max(a.yMin, b.yMin) - Math.min(a.yMax, b.yMax));
  return Math.hypot(dx, dy);
}

function clusterFragments(fragments, eps) {
  const n = fragments.length;
  const uf = makeUF(n);

  // Spatial grid for O(n) neighbor lookup.
  const cell = eps * 2;
  const grid = new Map();
  const key = (gx, gy) => `${gx},${gy}`;
  for (let i = 0; i < n; i++) {
    const f = fragments[i];
    const gx = Math.floor(f.cx / cell);
    const gy = Math.floor(f.cy / cell);
    const k = key(gx, gy);
    if (!grid.has(k)) grid.set(k, []);
    grid.get(k).push(i);
  }

  for (let i = 0; i < n; i++) {
    const f = fragments[i];
    const gx = Math.floor(f.cx / cell);
    const gy = Math.floor(f.cy / cell);
    for (let dy = -1; dy <= 1; dy++) {
      for (let dx = -1; dx <= 1; dx++) {
        const cellList = grid.get(key(gx + dx, gy + dy));
        if (!cellList) continue;
        for (const j of cellList) {
          if (j <= i) continue;
          if (bboxDistance(f, fragments[j]) <= eps) uf.union(i, j);
        }
      }
    }
  }

  const groups = new Map();
  for (let i = 0; i < n; i++) {
    const r = uf.find(i);
    if (!groups.has(r)) groups.set(r, []);
    groups.get(r).push(fragments[i]);
  }

  const clusters = [];
  for (const members of groups.values()) {
    // Reading order: top-to-bottom in vertical bands of ~6pt, then left-to-right.
    const sorted = members.slice().sort((a, b) => {
      const ya = Math.round(a.cy / 6);
      const yb = Math.round(b.cy / 6);
      if (ya !== yb) return ya - yb;
      return a.cx - b.cx;
    });
    const text = sorted.map(f => f.text).join("");
    let xMin = Infinity, yMin = Infinity, xMax = -Infinity, yMax = -Infinity;
    for (const m of members) {
      if (m.xMin < xMin) xMin = m.xMin;
      if (m.yMin < yMin) yMin = m.yMin;
      if (m.xMax > xMax) xMax = m.xMax;
      if (m.yMax > yMax) yMax = m.yMax;
    }
    clusters.push({
      text,
      xMin, yMin, xMax, yMax,
      cx: (xMin + xMax) / 2,
      cy: (yMin + yMax) / 2,
      fragmentCount: members.length,
    });
  }
  return clusters;
}

// ---------- fuzzy matching ----------

function normLetters(s) {
  return s.toLowerCase().replace(/[^a-z0-9]/g, "");
}

// Words too generic to count as a match signal on their own.
const STOPWORDS = new Set([
  "camp", "the", "of", "and", "for", "with", "at", "in", "on", "to",
  "a", "an", "is", "it", "by", "or", "but", "as", "be",
]);

// Tokenize an entry name into meaningful words (≥3 chars, not stopwords).
function meaningfulTokens(name) {
  return name.toLowerCase()
    .split(/[^a-z0-9]+/)
    .filter(t => t.length >= 3 && !STOPWORDS.has(t));
}

// Token-based match: a cluster matches an entry if its lowercased text
// contains the entry's tokens as substrings. Score = fraction of the
// entry's tokens found. We also weight longer tokens more heavily, so a
// 9-letter unique token counts for more than three 3-letter tokens.
function scoreMatch(entryName, clusterText) {
  const tokens = meaningfulTokens(entryName);
  if (!tokens.length) return 0;
  const text = normLetters(clusterText);
  if (text.length < 3) return 0;

  let matchedWeight = 0, totalWeight = 0;
  let longTokenMatched = false;
  for (const tok of tokens) {
    const w = tok.length;
    totalWeight += w;
    if (text.includes(tok)) {
      matchedWeight += w;
      if (tok.length >= 5) longTokenMatched = true;
    }
  }
  if (!longTokenMatched && tokens.every(t => t.length < 5)) {
    // Entry has only short tokens (e.g. "Big Top Rouge"). Allow it if
    // the cluster contains at least 2 of them.
    const hits = tokens.filter(t => text.includes(t)).length;
    if (hits < 2) return 0;
  } else if (!longTokenMatched) {
    // We had ≥1 long token but it didn't match — that's a strong
    // signal this isn't the right cluster.
    return 0;
  }
  return matchedWeight / totalWeight;
}

// ---------- main ----------

function main() {
  console.error("Rendering map...");
  renderMapImage();

  console.error("Parsing bbox...");
  const { pageWidth, pageHeight, fragments } = parseBbox();
  console.error(`  ${fragments.length} fragments on ${pageWidth.toFixed(0)}×${pageHeight.toFixed(0)}pt page`);

  console.error("Clustering...");
  const clusters = clusterFragments(fragments, CLUSTER_EPS_PT);
  console.error(`  ${clusters.length} clusters`);

  // Write labels file with normalized 0..1 coordinates so the UI is
  // resolution-independent.
  const norm = (x, max) => Math.round((x / max) * 10000) / 10000;
  const labels = clusters
    .filter(c => c.text.length >= MIN_LABEL_LEN)
    .map(c => ({
      text: c.text,
      x: norm(c.cx, pageWidth),
      y: norm(c.cy, pageHeight),
      xMin: norm(c.xMin, pageWidth),
      yMin: norm(c.yMin, pageHeight),
      xMax: norm(c.xMax, pageWidth),
      yMax: norm(c.yMax, pageHeight),
      fragmentCount: c.fragmentCount,
    }))
    .sort((a, b) => a.y - b.y || a.x - b.x);
  fs.writeFileSync(LABELS_OUT, JSON.stringify({
    pageWidth, pageHeight, labels,
  }, null, 2));
  console.error(`Wrote ${LABELS_OUT} — ${labels.length} labels`);

  // Load existing locations (if any). A pin survives re-runs if it is
  // either manually confirmed or was placed by something better than our
  // text fuzzy match (sub-agent vision, QA verification). The text
  // fuzzy match only fills in entries that have no pin at all yet — so
  // running the parser is idempotent and won't undo prior vision work.
  const existing = fs.existsSync(LOCATIONS_OUT)
    ? JSON.parse(fs.readFileSync(LOCATIONS_OUT, "utf8"))
    : { pins: [] };
  const existingByName = new Map((existing.pins || []).map(p => [p.name, p]));
  const isPreservable = p =>
    p.confirmed === true ||
    (typeof p.source === "string" && (p.source.startsWith("agent:") || p.source.startsWith("qa:")));
  const preservedNames = new Set(
    (existing.pins || []).filter(isPreservable).map(p => p.name)
  );
  const confirmedCount = (existing.pins || []).filter(p => p.confirmed).length;
  const preservedNonConfirmed = preservedNames.size - confirmedCount;

  console.error("Auto-matching entries to labels...");
  const events = JSON.parse(fs.readFileSync(EVENTS_JSON, "utf8"));
  const wantedTypes = new Set(["camp", "sound_stage", "art_installation"]);
  const entries = events.entries.filter(e => wantedTypes.has(e.type));

  const used = new Set();
  const pins = [];

  // Carry preserved pins through unchanged.
  for (const entry of entries) {
    if (preservedNames.has(entry.name)) {
      const ex = existingByName.get(entry.name);
      pins.push({ ...ex, type: entry.type });
    }
  }

  // Auto-suggest only for entries that have no pin at all yet.
  const todo = entries
    .filter(e => !preservedNames.has(e.name))
    .sort((a, b) => b.name.length - a.name.length);

  let suggested = 0;
  for (const entry of todo) {
    let best = { score: 0, idx: -1 };
    for (let i = 0; i < clusters.length; i++) {
      if (used.has(i)) continue;
      const c = clusters[i];
      if (c.text.length < MIN_LABEL_LEN) continue;
      const s = scoreMatch(entry.name, c.text);
      if (s > best.score) best = { score: s, idx: i };
    }
    if (best.idx >= 0 && best.score >= FUZZY_MIN_SCORE) {
      used.add(best.idx);
      const c = clusters[best.idx];
      pins.push({
        name: entry.name,
        type: entry.type,
        x: norm(c.cx, pageWidth),
        y: norm(c.cy, pageHeight),
        confidence: Math.round(best.score * 100) / 100,
        matchedText: c.text,
        confirmed: false,
      });
      suggested++;
    }
  }

  console.error(`  ${confirmedCount} confirmed + ${preservedNonConfirmed} agent/QA pins preserved`);
  console.error(`  ${suggested} new auto-suggestions (of ${todo.length} unplaced entries)`);

  // Stable order for diffs: by type then name.
  const typeOrder = ["camp", "sound_stage", "art_installation"];
  pins.sort((a, b) => {
    const ia = typeOrder.indexOf(a.type), ib = typeOrder.indexOf(b.type);
    if (ia !== ib) return ia - ib;
    return a.name.localeCompare(b.name, undefined, { sensitivity: "base" });
  });

  // Cache-busting version: short content hash of the rendered PNG.
  // The browser-side localStorage cache (in index.html) keys off this,
  // so re-runs that don't change the image keep the cached blob.
  const version = crypto
    .createHash("md5")
    .update(fs.readFileSync(MAP_PNG))
    .digest("hex")
    .slice(0, 12);

  const output = {
    pageWidth, pageHeight,
    version,
    imagePath: "map.png",
    pins,
  };
  fs.writeFileSync(LOCATIONS_OUT, JSON.stringify(output, null, 2));
  fs.writeFileSync(MAP_DATA_JS, `window.OTHERWORLD_MAP = ${JSON.stringify(output)};\n`);
  console.error(`Wrote ${LOCATIONS_OUT} and ${MAP_DATA_JS} (version ${version})`);
}

main();
