<?php
/**
 * Map annotation tool (management).
 *
 * Loads events.json + map-locations.json + map-labels.json server-side
 * and embeds the data into the page (no client-side fetch round-trip).
 * Saves go to admin/save-pins.php, which atomically writes the JSON
 * and regenerates map-data.js for the static front-end.
 */
declare(strict_types=1);

require __DIR__ . '/require-local.php';

$rootDir       = dirname(__DIR__);
$eventsPath    = $rootDir . '/events.json';
$locationsPath = $rootDir . '/map-locations.json';
$labelsPath    = $rootDir . '/map-labels.json';
$mapPngPath    = $rootDir . '/map.png';

function readJson(string $path, array $fallback): array {
    if (!file_exists($path)) return $fallback;
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') return $fallback;
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

$events    = readJson($eventsPath, ['entries' => []]);
$locations = readJson($locationsPath, ['pageWidth' => 1, 'pageHeight' => 1, 'pins' => []]);
$labels    = readJson($labelsPath,    ['labels'   => []]);

// Image served from the parent dir; cache-bust by content hash so a
// freshly-rendered map doesn't get served from the browser cache.
$mapImgUrl = '../map.png';
if (file_exists($mapPngPath)) {
    $mapImgUrl .= '?v=' . substr((string) md5_file($mapPngPath), 0, 8);
}

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Otherworld — Map annotator</title>
  <style>
    :root {
      --bg: #0f1a1f;
      --panel: #17252b;
      --panel-2: #1d2f37;
      --line: #2a4048;
      --ink: #e7eef0;
      --ink-dim: #8aa0a8;
      --ink-mid: #b9c7cd;
      --camp: #b6e36b;
      --stage: #ff7ab0;
      --art: #6cdfeb;
      --pinned: #ffb84a;
      --unpinned: #506974;
      --selected: #ffe66d;
      --danger: #e35d6a;
      --ok: #6cdf8e;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; margin: 0; }
    body {
      font: 13px/1.4 -apple-system, BlinkMacSystemFont, "Inter", system-ui, sans-serif;
      background: var(--bg);
      color: var(--ink);
      overflow: hidden;
    }
    .app { display: grid; grid-template-columns: 320px 1fr; grid-template-rows: 100vh; height: 100vh; }

    /* Sidebar */
    aside {
      background: var(--panel);
      border-right: 1px solid var(--line);
      display: flex;
      flex-direction: column;
      min-width: 0;
    }
    .sb-head {
      padding: 14px 14px 10px;
      border-bottom: 1px solid var(--line);
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .sb-head h1 {
      margin: 0;
      font-size: 14px;
      font-weight: 700;
      letter-spacing: 0.02em;
    }
    .sb-head .stats {
      font-size: 11px;
      color: var(--ink-dim);
      font-family: ui-monospace, Menlo, monospace;
    }
    .sb-head .save-status {
      font-size: 11px;
      color: var(--ink-dim);
      font-family: ui-monospace, Menlo, monospace;
      min-height: 14px;
    }
    .sb-head .save-status.dirty { color: var(--pinned); }
    .sb-head .save-status.saved { color: var(--ok); }
    .sb-head .save-status.error { color: var(--danger); }
    .sb-tools {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
    }
    .sb-tools input[type="search"] {
      flex: 1 1 100%;
      background: var(--panel-2);
      color: var(--ink);
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 6px 8px;
      font: inherit;
    }
    .sb-tools button, .sb-tools select {
      background: var(--panel-2);
      color: var(--ink);
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 5px 9px;
      font: inherit;
      cursor: pointer;
    }
    .sb-tools button:hover { background: var(--line); }
    .sb-tools button:disabled { opacity: 0.5; cursor: not-allowed; }
    .sb-tools button.primary {
      background: var(--camp);
      color: #14201a;
      border-color: var(--camp);
      font-weight: 600;
    }
    .sb-tools button.primary:hover { filter: brightness(1.1); }
    .sb-tools button.primary:disabled { background: var(--unpinned); border-color: var(--unpinned); color: var(--ink-dim); }
    .sb-list {
      flex: 1;
      overflow-y: auto;
      padding: 4px 0;
    }
    .sb-admin {
      border-top: 1px solid var(--line);
      padding: 10px 14px 12px;
      display: flex;
      flex-direction: column;
      gap: 6px;
      background: rgba(0,0,0,0.18);
    }
    .sb-admin h2 {
      margin: 0 0 2px;
      font-size: 10px;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: var(--ink-dim);
      font-weight: 600;
    }
    .sb-admin .row {
      display: flex;
      gap: 6px;
    }
    .sb-admin button {
      flex: 1;
      background: var(--panel-2);
      color: var(--ink);
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 6px 8px;
      font: inherit;
      cursor: pointer;
      text-align: left;
    }
    .sb-admin button:hover { background: var(--line); }
    .sb-admin button.warn {
      color: var(--danger);
      border-color: rgba(227, 93, 106, 0.35);
    }
    .sb-admin button.warn:hover {
      background: rgba(227, 93, 106, 0.18);
    }
    .sb-admin button:disabled { opacity: 0.5; cursor: not-allowed; }
    .sb-admin .log {
      font: 10.5px/1.35 ui-monospace, Menlo, monospace;
      color: var(--ink-dim);
      background: var(--bg);
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 6px 8px;
      max-height: 110px;
      overflow-y: auto;
      white-space: pre-wrap;
      word-break: break-word;
      display: none;
    }
    .sb-admin .log.show { display: block; }
    .sb-admin .log.error { color: var(--danger); border-color: rgba(227, 93, 106, 0.4); }
    .sb-admin .log.ok { color: var(--ok); border-color: rgba(108, 223, 142, 0.35); }
    .sb-item {
      display: grid;
      grid-template-columns: 14px 1fr auto auto;
      gap: 8px;
      align-items: center;
      padding: 6px 14px;
      cursor: pointer;
      font-size: 12.5px;
      border-left: 3px solid transparent;
    }
    .sb-item .rm-pin {
      background: transparent;
      color: var(--ink-dim);
      border: 0;
      font-size: 15px;
      line-height: 1;
      padding: 2px 6px;
      cursor: pointer;
      border-radius: 4px;
      opacity: 0;
      transition: opacity 0.12s, background 0.12s, color 0.12s;
    }
    .sb-item:hover .rm-pin,
    .sb-item.selected .rm-pin { opacity: 0.7; }
    .sb-item .rm-pin:hover {
      opacity: 1;
      background: rgba(227, 93, 106, 0.18);
      color: var(--danger);
    }
    .sb-item:hover { background: var(--panel-2); }
    .sb-item.selected {
      background: var(--panel-2);
      border-left-color: var(--selected);
    }
    .sb-item .dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: var(--unpinned);
    }
    .sb-item.pinned .dot { background: var(--pinned); }
    .sb-item.verified .dot { background: var(--ok, #6cdf8e); }
    .sb-item.qa-wrong .dot { background: var(--danger); box-shadow: 0 0 0 2px rgba(227, 93, 106, 0.25); }
    .sb-item.qa-wrong .name { color: var(--danger); }
    .sb-item .name {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .sb-item .type-tag {
      font-size: 10px;
      color: var(--ink-dim);
      font-family: ui-monospace, Menlo, monospace;
      text-transform: uppercase;
    }
    .sb-item[data-type="camp"] .type-tag { color: var(--camp); }
    .sb-item[data-type="sound_stage"] .type-tag { color: var(--stage); }
    .sb-item[data-type="art_installation"] .type-tag { color: var(--art); }

    /* Main map area */
    main {
      position: relative;
      overflow: hidden;
      background: #0a1216;
      cursor: crosshair;
    }
    main.dragging { cursor: grabbing; }
    main.no-selection { cursor: default; }
    .canvas {
      position: absolute;
      top: 0; left: 0;
      transform-origin: 0 0;
      user-select: none;
    }
    .canvas img {
      display: block;
      max-width: none;
      pointer-events: none;
    }
    .pin {
      position: absolute;
      width: 14px; height: 14px;
      margin-left: -7px;
      margin-top: -7px;
      border-radius: 50%;
      border: 2px solid #0f1a1f;
      box-shadow: 0 1px 3px rgba(0,0,0,0.5);
      cursor: grab;
      transition: transform 0.1s;
    }
    .pin:hover { transform: scale(1.4); z-index: 2; }
    .pin.selected {
      box-shadow: 0 0 0 3px var(--selected), 0 2px 6px rgba(0,0,0,0.6);
      transform: scale(1.3);
      z-index: 3;
    }
    .pin[data-type="camp"] { background: var(--camp); }
    .pin[data-type="sound_stage"] { background: var(--stage); }
    .pin[data-type="art_installation"] { background: var(--art); }
    .pin.suggested { opacity: 0.65; border-style: dashed; }
    .pin.verified {
      border-color: var(--ok, #6cdf8e);
      box-shadow: 0 0 0 1px var(--ok, #6cdf8e), 0 1px 3px rgba(0,0,0,0.5);
    }
    .pin.qa-wrong {
      border-color: var(--danger);
      box-shadow: 0 0 0 2px var(--danger), 0 1px 3px rgba(0,0,0,0.5);
      animation: qa-wrong-pulse 1.4s ease-in-out infinite;
    }
    @keyframes qa-wrong-pulse {
      0%, 100% { box-shadow: 0 0 0 2px var(--danger), 0 1px 3px rgba(0,0,0,0.5); }
      50%      { box-shadow: 0 0 0 5px rgba(227,93,106,0.35), 0 1px 3px rgba(0,0,0,0.5); }
    }
    .label-hint {
      position: absolute;
      transform: translate(-50%, -50%);
      color: rgba(255, 230, 109, 0.85);
      background: rgba(15, 26, 31, 0.7);
      padding: 1px 4px;
      border-radius: 3px;
      font-size: 10px;
      font-family: ui-monospace, Menlo, monospace;
      pointer-events: none;
      white-space: nowrap;
      max-width: 200px;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* Pin hover tooltip (positioned in `main`, not `.canvas`, so it isn't
       scaled by the zoom transform). */
    .pin-tooltip {
      position: absolute;
      z-index: 20;
      background: rgba(15, 26, 31, 0.96);
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 6px 9px;
      font-size: 12px;
      color: var(--ink);
      pointer-events: none;
      max-width: 280px;
      box-shadow: 0 4px 14px rgba(0,0,0,0.45);
      opacity: 0;
      transform: translateY(2px);
      transition: opacity 0.12s, transform 0.12s;
      display: none;
    }
    .pin-tooltip.show { opacity: 1; transform: translateY(0); }
    .pin-tooltip[data-status="confirmed"] { border-color: var(--ok); }
    .pin-tooltip[data-status="verified"]  { border-color: var(--ok); }
    .pin-tooltip[data-status="qa-wrong"]  { border-color: var(--danger); }
    .pin-tooltip .tt-name { font-weight: 600; margin-bottom: 2px; }
    .pin-tooltip .tt-meta {
      font-size: 10.5px;
      color: var(--ink-dim);
      font-family: ui-monospace, Menlo, monospace;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .pin-tooltip .tt-evidence {
      margin-top: 5px;
      padding-top: 5px;
      border-top: 1px solid var(--line);
      font-size: 11px;
      color: var(--ink-mid);
      line-height: 1.35;
    }

    /* Toolbar */
    .toolbar {
      position: absolute;
      top: 12px; left: 12px;
      display: flex;
      gap: 6px;
      align-items: center;
      background: rgba(15, 26, 31, 0.92);
      backdrop-filter: blur(6px);
      padding: 6px 10px;
      border-radius: 8px;
      border: 1px solid var(--line);
      z-index: 10;
    }
    .toolbar button, .toolbar label {
      background: transparent;
      color: var(--ink);
      border: 0;
      padding: 4px 8px;
      font: inherit;
      cursor: pointer;
      border-radius: 4px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .toolbar button:hover { background: var(--panel-2); }
    .toolbar input[type="checkbox"] { margin: 0; }
    .toolbar .sep { width: 1px; align-self: stretch; background: var(--line); margin: 0 2px; }
    .toolbar .zoom { font-family: ui-monospace, Menlo, monospace; font-size: 11px; color: var(--ink-dim); min-width: 38px; text-align: center; }

    .help {
      position: absolute;
      bottom: 12px; left: 12px;
      background: rgba(15, 26, 31, 0.92);
      backdrop-filter: blur(6px);
      padding: 8px 12px;
      border-radius: 8px;
      border: 1px solid var(--line);
      font-size: 11.5px;
      color: var(--ink-mid);
      z-index: 10;
      max-width: 420px;
    }
    .help kbd {
      background: var(--panel-2);
      border: 1px solid var(--line);
      border-radius: 3px;
      padding: 1px 4px;
      font: 10px ui-monospace, Menlo, monospace;
    }
  </style>
</head>
<body>
<div class="app">
  <aside>
    <div class="sb-head">
      <h1>Map annotator</h1>
      <div class="stats" id="stats">loading…</div>
      <div class="save-status" id="saveStatus"></div>
      <div class="sb-tools">
        <input type="search" id="filter" placeholder="Search…">
        <select id="typeFilter">
          <option value="all">All types</option>
          <option value="camp">Camps</option>
          <option value="sound_stage">Stages</option>
          <option value="art_installation">Art</option>
        </select>
        <select id="pinFilter">
          <option value="all">All</option>
          <option value="unpinned">Unpinned only</option>
          <option value="pinned">Pinned only</option>
          <option value="qa-wrong">QA-flagged wrong</option>
          <option value="verified">QA-verified</option>
        </select>
        <button id="saveBtn" class="primary" disabled>Save to server</button>
        <button id="downloadBtn" title="Download JSON backup">⬇</button>
        <button id="copyBtn" title="Copy JSON to clipboard">⎘</button>
      </div>
    </div>
    <div class="sb-list" id="list"></div>
    <div class="sb-admin">
      <h2>Admin</h2>
      <div class="row">
        <button id="reparseBtn" title="Re-run node parse-map.js — auto-suggests positions for any new/unpinned entries. Confirmed pins are preserved.">↻ Re-run parser</button>
      </div>
      <div class="row">
        <button id="resetBtn" class="warn" title="Clear every pin (confirmed and suggested). Map and labels are kept.">⌫ Reset pins</button>
        <button id="deleteMapBtn" class="warn" title="Delete the rendered map image and all derived files (pins, labels, runtime data). Re-run the parser to regenerate from map.pdf.">⌫ Delete parsed map</button>
      </div>
      <div class="log" id="adminLog"></div>
    </div>
  </aside>
  <main id="main">
    <div class="toolbar">
      <button id="zoomOut">−</button>
      <span class="zoom" id="zoomLabel">100%</span>
      <button id="zoomIn">+</button>
      <button id="zoomFit">Fit</button>
      <button id="zoom1">1:1</button>
      <span class="sep"></span>
      <label><input type="checkbox" id="showLabels"> Show map labels</label>
      <label><input type="checkbox" id="showSuggested" checked> Show suggested</label>
    </div>
    <div class="canvas" id="canvas">
      <img id="mapImg" alt="map" src="<?= htmlspecialchars($mapImgUrl, ENT_QUOTES) ?>">
    </div>
    <div class="pin-tooltip" id="pinTooltip" role="tooltip"></div>
    <div class="help">
      Click a sidebar entry, then click on the map to drop a pin. Drag pins to refine.
      Delete pins by right-clicking, the <kbd>×</kbd> in the sidebar, or <kbd>Del</kbd> when selected.
      <kbd>Esc</kbd> deselect. <kbd>Space</kbd>+drag to pan, scroll to zoom.
      <kbd>⌘Z</kbd>/<kbd>Ctrl+Z</kbd> undoes the last place/move/delete.
      Auto-saves every 8s when there are unsaved changes; <kbd>⌘S</kbd> saves now.
    </div>
  </main>
</div>

<script>
window.INITIAL = {
  events:    <?= json_encode($events,    $jsonFlags) ?>,
  locations: <?= json_encode($locations, $jsonFlags) ?>,
  labels:    <?= json_encode($labels,    $jsonFlags) ?>
};
</script>
<script>
const SAVE_URL = 'save-pins.php';
const AUTOSAVE_MS = 8000;
const UNDO_MAX = 50;

const state = {
  pageWidth: INITIAL.locations.pageWidth || 1,
  pageHeight: INITIAL.locations.pageHeight || 1,
  img: { w: 0, h: 0 },
  zoom: 1,
  pan: { x: 0, y: 0 },
  entries: [],
  pins: new Map(),
  labels: INITIAL.labels.labels || [],
  selectedName: null,
  draggingPin: null,
  panning: false,
  panStart: null,
  spaceDown: false,
  dirty: false,
  saving: false,
  lastSavedAt: null,
  saveTimer: null,
  undoStack: [],
};

const els = {
  main: document.getElementById('main'),
  canvas: document.getElementById('canvas'),
  img: document.getElementById('mapImg'),
  list: document.getElementById('list'),
  stats: document.getElementById('stats'),
  saveStatus: document.getElementById('saveStatus'),
  filter: document.getElementById('filter'),
  typeFilter: document.getElementById('typeFilter'),
  pinFilter: document.getElementById('pinFilter'),
  saveBtn: document.getElementById('saveBtn'),
  downloadBtn: document.getElementById('downloadBtn'),
  copyBtn: document.getElementById('copyBtn'),
  showLabels: document.getElementById('showLabels'),
  showSuggested: document.getElementById('showSuggested'),
  zoomIn: document.getElementById('zoomIn'),
  zoomOut: document.getElementById('zoomOut'),
  zoomFit: document.getElementById('zoomFit'),
  zoom1: document.getElementById('zoom1'),
  zoomLabel: document.getElementById('zoomLabel'),
  reparseBtn: document.getElementById('reparseBtn'),
  resetBtn: document.getElementById('resetBtn'),
  deleteMapBtn: document.getElementById('deleteMapBtn'),
  adminLog: document.getElementById('adminLog'),
  pinTooltip: document.getElementById('pinTooltip'),
};

const WANTED_TYPES = new Set(['camp', 'sound_stage', 'art_installation']);
const TYPE_LABEL = { camp: 'Camp', sound_stage: 'Stage', art_installation: 'Art' };

function init() {
  state.entries = INITIAL.events.entries
    .filter(e => WANTED_TYPES.has(e.type))
    .sort((a, b) => a.name.localeCompare(b.name, undefined, {sensitivity: 'base'}));

  for (const p of (INITIAL.locations.pins || [])) {
    state.pins.set(p.name, {
      x: p.x, y: p.y, type: p.type,
      confidence: p.confidence,
      matchedText: p.matchedText,
      confirmed: !!p.confirmed,
      verified: !!p.verified,
      qaWrong: !!p.qaWrong,
      qaEvidence: p.qaEvidence,
      qaVerdict: p.qaVerdict,
      source: p.source,
    });
  }

  // The image src is already set by PHP; wait for it to load to learn
  // natural dimensions before fitting.
  if (els.img.complete && els.img.naturalWidth) {
    onImageReady();
  } else {
    els.img.addEventListener('load', onImageReady);
  }
  renderList();
  renderLabels();
  updateStats();
  updateSaveStatus();
  bindEvents();
  startAutosave();
}

function onImageReady() {
  state.img.w = els.img.naturalWidth;
  state.img.h = els.img.naturalHeight;
  fitToScreen();
  renderPins();
  renderLabels();
}

function markDirty() {
  state.dirty = true;
  updateSaveStatus();
}

// ---------- undo ----------
// Snapshot-based: before each mutation (place / move / delete) we copy
// the full pin map onto a stack. Cmd/Ctrl+Z pops and restores.

function pushUndo() {
  const snap = [];
  for (const [name, p] of state.pins) snap.push([name, { ...p }]);
  state.undoStack.push(snap);
  if (state.undoStack.length > UNDO_MAX) state.undoStack.shift();
}

function undo() {
  if (!state.undoStack.length) {
    flashSaveStatus('nothing to undo');
    return;
  }
  const snap = state.undoStack.pop();
  state.pins = new Map(snap);
  // If the currently-selected entry's pin no longer exists, leave the
  // selection alone — user might want to re-place it.
  markDirty();
  renderList();
  renderPins();
  updateStats();
  flashSaveStatus(state.undoStack.length
    ? `undid (${state.undoStack.length} more)`
    : 'undid');
}

function flashSaveStatus(text) {
  updateSaveStatus({ kind: 'dirty', text });
  // Restore the standard label shortly after.
  setTimeout(updateSaveStatus, 1200);
}

function updateStats() {
  const t = state.entries.length;
  let pinned = 0, verified = 0, qaWrong = 0, suggested = 0;
  for (const e of state.entries) {
    const p = state.pins.get(e.name);
    if (!p) continue;
    if (p.confirmed) pinned++;
    else if (p.qaWrong) qaWrong++;
    else if (p.verified) verified++;
    else suggested++;
  }
  const unplaced = t - pinned - verified - qaWrong - suggested;
  const parts = [
    pinned   ? `${pinned} confirmed` : null,
    verified ? `${verified} verified` : null,
    qaWrong  ? `${qaWrong} QA flagged` : null,
    suggested ? `${suggested} suggested` : null,
    `${unplaced} unplaced`,
    `(${t} total)`,
  ].filter(Boolean);
  els.stats.textContent = parts.join(' · ');
}

function updateSaveStatus(msg) {
  els.saveBtn.disabled = state.saving || !state.dirty;
  els.saveStatus.classList.remove('dirty', 'saved', 'error');
  if (msg && msg.kind) {
    els.saveStatus.classList.add(msg.kind);
    els.saveStatus.textContent = msg.text;
    return;
  }
  if (state.saving) {
    els.saveStatus.textContent = 'saving…';
  } else if (state.dirty) {
    els.saveStatus.classList.add('dirty');
    els.saveStatus.textContent = 'unsaved changes';
  } else if (state.lastSavedAt) {
    els.saveStatus.classList.add('saved');
    els.saveStatus.textContent = 'saved ' + relativeTime(state.lastSavedAt);
  } else {
    els.saveStatus.textContent = '';
  }
}

function relativeTime(ts) {
  const s = Math.round((Date.now() - ts) / 1000);
  if (s < 5) return 'just now';
  if (s < 60) return s + 's ago';
  const m = Math.round(s / 60);
  if (m < 60) return m + 'm ago';
  const h = Math.round(m / 60);
  return h + 'h ago';
}

function entryMatchesFilter(e) {
  const q = els.filter.value.trim().toLowerCase();
  if (q && !e.name.toLowerCase().includes(q)) return false;
  const tf = els.typeFilter.value;
  if (tf !== 'all' && e.type !== tf) return false;
  const pf = els.pinFilter.value;
  const p = state.pins.get(e.name);
  if (pf === 'pinned'   && !(p && p.confirmed)) return false;
  if (pf === 'unpinned' &&   p && p.confirmed)  return false;
  if (pf === 'qa-wrong' && !(p && p.qaWrong))   return false;
  if (pf === 'verified' && !(p && p.verified))  return false;
  return true;
}

function renderList() {
  els.list.innerHTML = '';
  for (const e of state.entries) {
    if (!entryMatchesFilter(e)) continue;
    const row = document.createElement('div');
    row.className = 'sb-item';
    row.dataset.name = e.name;
    row.dataset.type = e.type;
    const p = state.pins.get(e.name);
    if (p && p.confirmed) row.classList.add('pinned');
    if (p && p.verified) row.classList.add('verified');
    if (p && p.qaWrong) row.classList.add('qa-wrong');
    if (e.name === state.selectedName) row.classList.add('selected');
    const hasPin = !!p;
    row.innerHTML = `
      <div class="dot"></div>
      <div class="name" title="${escapeAttr(e.name)}">${escapeHtml(e.name)}</div>
      <div class="type-tag">${TYPE_LABEL[e.type]}</div>
      ${hasPin ? `<button class="rm-pin" title="Delete pin (Del)" aria-label="Delete pin for ${escapeAttr(e.name)}">×</button>` : ''}
    `;
    row.addEventListener('click', () => selectEntry(e.name));
    if (hasPin) {
      row.querySelector('.rm-pin').addEventListener('click', ev => {
        ev.stopPropagation();
        deletePin(e.name);
      });
    }
    els.list.appendChild(row);
  }
}

function selectEntry(name) {
  state.selectedName = name;
  els.main.classList.toggle('no-selection', !name);
  renderList();
  const p = state.pins.get(name);
  if (p) centerOn(p.x, p.y);
  renderPins();
}

function escapeHtml(s) { return String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
function escapeAttr(s) { return escapeHtml(s).replace(/"/g, '&quot;'); }

function renderPins() {
  els.canvas.querySelectorAll('.pin').forEach(n => n.remove());
  if (!state.img.w) return;
  const hideSuggested = !els.showSuggested.checked;
  for (const [name, p] of state.pins) {
    if (hideSuggested && !p.confirmed) continue;
    const pin = document.createElement('div');
    let cls = 'pin' + (p.confirmed ? '' : ' suggested');
    if (p.verified) cls += ' verified';
    if (p.qaWrong) cls += ' qa-wrong';
    pin.className = cls;
    if (name === state.selectedName) pin.classList.add('selected');
    pin.dataset.name = name;
    pin.dataset.type = p.type;
    pin.style.left = (p.x * state.img.w) + 'px';
    pin.style.top = (p.y * state.img.h) + 'px';
    pin.addEventListener('mousedown', e => startPinDrag(e, name));
    pin.addEventListener('contextmenu', e => { e.preventDefault(); deletePin(name); });
    pin.addEventListener('click', e => { e.stopPropagation(); selectEntry(name); });
    pin.addEventListener('mouseenter', () => showPinTooltip(name, pin));
    pin.addEventListener('mouseleave', hidePinTooltip);
    els.canvas.appendChild(pin);
  }
}

function showPinTooltip(name, pinEl) {
  const p = state.pins.get(name);
  if (!p) return;
  const t = els.pinTooltip;

  let status, statusLabel;
  if (p.qaWrong)        { status = 'qa-wrong';  statusLabel = 'QA flagged wrong'; }
  else if (p.confirmed) { status = 'confirmed'; statusLabel = 'Confirmed'; }
  else if (p.verified)  { status = 'verified';  statusLabel = 'QA verified'; }
  else                  { status = 'suggested'; statusLabel = 'Suggested'; }

  const meta = [TYPE_LABEL[p.type] || p.type, statusLabel];
  if (p.confidence != null) meta.push('conf ' + p.confidence.toFixed(2));

  const evidence = p.qaEvidence
    ? 'QA: ' + p.qaEvidence
    : (p.matchedText || '');

  t.dataset.status = status;
  t.innerHTML = '';
  const nameEl = document.createElement('div');
  nameEl.className = 'tt-name';
  nameEl.textContent = name;
  t.appendChild(nameEl);
  const metaEl = document.createElement('div');
  metaEl.className = 'tt-meta';
  metaEl.textContent = meta.join(' · ');
  t.appendChild(metaEl);
  if (evidence) {
    const evEl = document.createElement('div');
    evEl.className = 'tt-evidence';
    evEl.textContent = evidence;
    t.appendChild(evEl);
  }

  // Make it measurable, then position it relative to the main pane (the
  // tooltip is a sibling of .canvas, so use pin's screen coords and offset).
  t.style.display = 'block';
  const pinRect  = pinEl.getBoundingClientRect();
  const mainRect = els.main.getBoundingClientRect();
  const ttRect   = t.getBoundingClientRect();

  let left = pinRect.left - mainRect.left + pinRect.width / 2 - ttRect.width / 2;
  let top  = pinRect.top  - mainRect.top  - ttRect.height - 10;

  // Clamp horizontally.
  const margin = 6;
  if (left < margin) left = margin;
  if (left + ttRect.width > mainRect.width - margin) {
    left = mainRect.width - margin - ttRect.width;
  }
  // Flip below the pin if there isn't room above.
  if (top < margin) top = pinRect.bottom - mainRect.top + 10;

  t.style.left = left + 'px';
  t.style.top  = top  + 'px';
  // Next frame so transition fires.
  requestAnimationFrame(() => t.classList.add('show'));
}

function hidePinTooltip() {
  const t = els.pinTooltip;
  t.classList.remove('show');
  // Wait for fade-out, then take it out of layout so it doesn't affect
  // future measurements at a stale position.
  setTimeout(() => {
    if (!t.classList.contains('show')) t.style.display = 'none';
  }, 140);
}

function renderLabels() {
  els.canvas.querySelectorAll('.label-hint').forEach(n => n.remove());
  if (!els.showLabels.checked || !state.img.w) return;
  for (const l of state.labels) {
    if (l.text.length < 4) continue;
    const el = document.createElement('div');
    el.className = 'label-hint';
    el.style.left = (l.x * state.img.w) + 'px';
    el.style.top = (l.y * state.img.h) + 'px';
    el.textContent = l.text;
    els.canvas.appendChild(el);
  }
}

function setTransform() {
  els.canvas.style.transform = `translate(${state.pan.x}px, ${state.pan.y}px) scale(${state.zoom})`;
  els.zoomLabel.textContent = Math.round(state.zoom * 100) + '%';
}

function fitToScreen() {
  if (!state.img.w) return;
  const rect = els.main.getBoundingClientRect();
  const sx = rect.width / state.img.w;
  const sy = rect.height / state.img.h;
  state.zoom = Math.min(sx, sy) * 0.96;
  state.pan.x = (rect.width - state.img.w * state.zoom) / 2;
  state.pan.y = (rect.height - state.img.h * state.zoom) / 2;
  setTransform();
}

function setZoom(newZoom, anchorClientX, anchorClientY) {
  newZoom = Math.max(0.1, Math.min(8, newZoom));
  const rect = els.main.getBoundingClientRect();
  const ax = (anchorClientX ?? rect.width / 2) - rect.left;
  const ay = (anchorClientY ?? rect.height / 2) - rect.top;
  const imgX = (ax - state.pan.x) / state.zoom;
  const imgY = (ay - state.pan.y) / state.zoom;
  state.zoom = newZoom;
  state.pan.x = ax - imgX * state.zoom;
  state.pan.y = ay - imgY * state.zoom;
  setTransform();
}

function centerOn(normX, normY) {
  const rect = els.main.getBoundingClientRect();
  const px = normX * state.img.w * state.zoom;
  const py = normY * state.img.h * state.zoom;
  state.pan.x = rect.width / 2 - px;
  state.pan.y = rect.height / 2 - py;
  setTransform();
}

function startPinDrag(e, name) {
  e.preventDefault();
  e.stopPropagation();
  pushUndo();
  state.draggingPin = name;
  state.selectedName = name;
  hidePinTooltip();
  els.main.classList.add('dragging');
  renderList();
  renderPins();
}

function deletePin(name) {
  if (!confirm(`Delete pin for "${name}"?`)) return;
  pushUndo();
  state.pins.delete(name);
  markDirty();
  renderList();
  renderPins();
  updateStats();
}

function placePinAt(clientX, clientY) {
  if (!state.selectedName) return;
  const rect = els.main.getBoundingClientRect();
  const ax = clientX - rect.left;
  const ay = clientY - rect.top;
  const imgX = (ax - state.pan.x) / state.zoom;
  const imgY = (ay - state.pan.y) / state.zoom;
  const nx = imgX / state.img.w;
  const ny = imgY / state.img.h;
  if (nx < 0 || nx > 1 || ny < 0 || ny > 1) return;
  const entry = state.entries.find(e => e.name === state.selectedName);
  if (!entry) return;
  pushUndo();
  state.pins.set(state.selectedName, {
    x: round(nx), y: round(ny),
    type: entry.type, confirmed: true,
  });
  markDirty();
  // Move to next unpinned entry to streamline workflow.
  const idx = state.entries.findIndex(e => e.name === state.selectedName);
  for (let i = 1; i <= state.entries.length; i++) {
    const next = state.entries[(idx + i) % state.entries.length];
    if (!state.pins.has(next.name) || !state.pins.get(next.name).confirmed) {
      state.selectedName = next.name;
      break;
    }
  }
  renderList();
  renderPins();
  updateStats();
}

function round(n) { return Math.round(n * 10000) / 10000; }

function bindEvents() {
  els.filter.addEventListener('input', renderList);
  els.typeFilter.addEventListener('change', renderList);
  els.pinFilter.addEventListener('change', renderList);
  els.showLabels.addEventListener('change', renderLabels);
  els.showSuggested.addEventListener('change', renderPins);
  els.saveBtn.addEventListener('click', saveToServer);
  els.downloadBtn.addEventListener('click', downloadJSON);
  els.copyBtn.addEventListener('click', copyJSON);
  els.reparseBtn.addEventListener('click', adminReparse);
  els.resetBtn.addEventListener('click', adminResetPins);
  els.deleteMapBtn.addEventListener('click', adminDeleteMap);
  els.zoomIn.addEventListener('click', () => setZoom(state.zoom * 1.25));
  els.zoomOut.addEventListener('click', () => setZoom(state.zoom / 1.25));
  els.zoomFit.addEventListener('click', fitToScreen);
  els.zoom1.addEventListener('click', () => setZoom(1));

  els.main.addEventListener('click', e => {
    if (e.target.closest('.pin')) return;
    if (e.target.closest('.toolbar')) return;
    if (e.target.closest('.help')) return;
    // Don't drop a pin if the user was panning (held Space, or middle-mouse
    // pan); the click is the tail of a pan gesture, not a placement.
    if (state.spaceDown || state.justPanned) return;
    placePinAt(e.clientX, e.clientY);
  });

  els.main.addEventListener('mousemove', e => {
    if (state.draggingPin) {
      const rect = els.main.getBoundingClientRect();
      const ax = e.clientX - rect.left;
      const ay = e.clientY - rect.top;
      const imgX = (ax - state.pan.x) / state.zoom;
      const imgY = (ay - state.pan.y) / state.zoom;
      const p = state.pins.get(state.draggingPin);
      p.x = round(imgX / state.img.w);
      p.y = round(imgY / state.img.h);
      p.confirmed = true;
      // User manually adjusted — clear the QA-wrong flag.
      p.qaWrong = false;
      p.verified = true;
      markDirty();
      renderPins();
    } else if (state.panning) {
      state.pan.x = e.clientX - state.panStart.x;
      state.pan.y = e.clientY - state.panStart.y;
      setTransform();
    }
  });
  els.main.addEventListener('mouseup', () => {
    if (state.draggingPin) {
      state.draggingPin = null;
      els.main.classList.remove('dragging');
      updateStats();
      renderList();
    }
    if (state.panning) {
      // The browser fires a 'click' after mouseup; mark it so the click
      // handler can skip placePinAt (covers the case where Space was
      // released between mousedown and mouseup).
      state.justPanned = true;
    }
    state.panning = false;
  });
  els.main.addEventListener('mousedown', e => {
    state.justPanned = false;
    if (e.target.closest('.pin')) return;
    if (e.button === 1 || state.spaceDown) {
      e.preventDefault();
      state.panning = true;
      state.panStart = { x: e.clientX - state.pan.x, y: e.clientY - state.pan.y };
      els.main.classList.add('dragging');
    }
  });
  els.main.addEventListener('wheel', e => {
    e.preventDefault();
    const factor = Math.exp(-e.deltaY * 0.0015);
    setZoom(state.zoom * factor, e.clientX, e.clientY);
  }, { passive: false });

  window.addEventListener('keydown', e => {
    const inField = document.activeElement && ['INPUT','SELECT','TEXTAREA'].includes(document.activeElement.tagName);
    if (e.key === ' ' && !inField) {
      state.spaceDown = true;
      els.main.style.cursor = 'grab';
      e.preventDefault();
    }
    if (e.key === 'Escape') {
      state.selectedName = null;
      renderList();
      renderPins();
    }
    if ((e.key === 'Delete' || e.key === 'Backspace') && !inField && state.selectedName) {
      if (state.pins.has(state.selectedName)) {
        e.preventDefault();
        deletePin(state.selectedName);
      }
    }
    if ((e.key === 's' || e.key === 'S') && (e.metaKey || e.ctrlKey)) {
      e.preventDefault();
      if (state.dirty && !state.saving) saveToServer();
    }
    if ((e.key === 'z' || e.key === 'Z') && (e.metaKey || e.ctrlKey) && !e.shiftKey && !inField) {
      e.preventDefault();
      undo();
    }
  });
  window.addEventListener('keyup', e => {
    if (e.key === ' ') {
      state.spaceDown = false;
      els.main.style.cursor = '';
    }
  });
  window.addEventListener('beforeunload', e => {
    if (state.dirty) { e.preventDefault(); e.returnValue = ''; }
  });
  // Refresh the "saved Ns ago" stamp every 5s.
  setInterval(() => { if (!state.dirty && !state.saving) updateSaveStatus(); }, 5000);
}

function startAutosave() {
  setInterval(() => {
    if (state.dirty && !state.saving) saveToServer();
  }, AUTOSAVE_MS);
}

// ---------- save / export ----------

function buildPayload() {
  const order = ['camp', 'sound_stage', 'art_installation'];
  const arr = [];
  for (const [name, p] of state.pins) {
    arr.push({ name, type: p.type, x: p.x, y: p.y,
      confirmed: !!p.confirmed,
      ...(p.confidence != null ? { confidence: p.confidence } : {}),
      ...(p.matchedText ? { matchedText: p.matchedText } : {}),
      ...(p.source     ? { source:     p.source     } : {}),
      ...(p.verified   ? { verified:   true         } : {}),
      ...(p.qaWrong    ? { qaWrong:    true         } : {}),
      ...(p.qaVerdict  ? { qaVerdict:  p.qaVerdict  } : {}),
      ...(p.qaEvidence ? { qaEvidence: p.qaEvidence } : {}),
    });
  }
  arr.sort((a, b) => {
    const ia = order.indexOf(a.type), ib = order.indexOf(b.type);
    if (ia !== ib) return ia - ib;
    return a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
  });
  return { pins: arr };
}

async function saveToServer() {
  if (state.saving) return;
  state.saving = true;
  updateSaveStatus();
  try {
    const resp = await fetch(SAVE_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(buildPayload()),
    });
    const body = await resp.json().catch(() => ({}));
    if (!resp.ok || !body.ok) {
      throw new Error(body.error || ('HTTP ' + resp.status));
    }
    state.dirty = false;
    state.lastSavedAt = Date.now();
    updateSaveStatus();
  } catch (e) {
    updateSaveStatus({ kind: 'error', text: 'save failed: ' + e.message });
  } finally {
    state.saving = false;
    els.saveBtn.disabled = state.saving || !state.dirty;
  }
}

function buildBackup() {
  return { pageWidth: state.pageWidth, pageHeight: state.pageHeight, ...buildPayload() };
}

function downloadJSON() {
  const blob = new Blob([JSON.stringify(buildBackup(), null, 2)], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'map-locations.json';
  a.click();
  URL.revokeObjectURL(a.href);
}

async function copyJSON() {
  await navigator.clipboard.writeText(JSON.stringify(buildBackup(), null, 2));
  const orig = els.copyBtn.textContent;
  els.copyBtn.textContent = '✓';
  setTimeout(() => els.copyBtn.textContent = orig, 1200);
}

// ---------- admin actions ----------

function setAdminBusy(busy) {
  for (const b of [els.reparseBtn, els.resetBtn, els.deleteMapBtn]) b.disabled = busy;
}

function showAdminLog(text, kind) {
  els.adminLog.textContent = text;
  els.adminLog.classList.add('show');
  els.adminLog.classList.remove('ok', 'error');
  if (kind) els.adminLog.classList.add(kind);
}

async function postJSON(url) {
  const resp = await fetch(url, { method: 'POST' });
  const body = await resp.json().catch(() => ({}));
  if (!resp.ok || body.error) throw new Error(body.error || ('HTTP ' + resp.status));
  return body;
}

// Apply a server-side change (reset / re-parse / delete) by re-fetching
// the canonical pin list from disk via save-pins.php is overkill;
// simplest is to reload the page so all server-rendered data is fresh.
function reloadAfter(msg, delay = 700) {
  showAdminLog(msg, 'ok');
  setTimeout(() => window.location.reload(), delay);
}

async function adminReparse() {
  if (state.dirty && !confirm('You have unsaved pin edits. Re-running the parser will not touch them, but you should save first. Continue anyway?')) return;
  setAdminBusy(true);
  showAdminLog('Running parse-map.js…');
  try {
    const body = await postJSON('run-parser.php');
    const stats = body.stats || {};
    reloadAfter(`Parser done · ${stats.total ?? '?'} pins total · ${stats.confirmed ?? '?'} confirmed.\n\n${body.log || ''}`);
  } catch (e) {
    showAdminLog('Re-run failed: ' + e.message, 'error');
    setAdminBusy(false);
  }
}

async function adminResetPins() {
  if (!confirm('Clear every pin (confirmed and suggested)? The map image and labels stay.')) return;
  setAdminBusy(true);
  showAdminLog('Clearing pins…');
  try {
    await postJSON('reset-pins.php');
    reloadAfter('All pins cleared.');
  } catch (e) {
    showAdminLog('Reset failed: ' + e.message, 'error');
    setAdminBusy(false);
  }
}

async function adminDeleteMap() {
  const a = 'Delete the parsed map?\n\nThis removes:\n  · map.png (the rendered image)\n  · map-locations.json (all pins)\n  · map-labels.json (text clusters)\n  · map-data.js (runtime data)\n\nmap.pdf is NOT touched. Re-run the parser to regenerate.';
  if (!confirm(a)) return;
  setAdminBusy(true);
  showAdminLog('Deleting…');
  try {
    const body = await postJSON('delete-map.php');
    reloadAfter('Deleted: ' + (body.deleted || []).join(', '));
  } catch (e) {
    showAdminLog('Delete failed: ' + e.message, 'error');
    setAdminBusy(false);
  }
}

init();
</script>
</body>
</html>
