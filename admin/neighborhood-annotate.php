<?php
/**
 * Neighborhood annotator (management).
 *
 * Sibling of map-annotate.php. Same pan/zoom/sidebar UX, but instead of
 * single-pin placement you define a 4-point polygon per neighborhood —
 * any quadrilateral (rectangle, parallelogram, trapezoid…) by clicking
 * four corners on the map and dragging them.
 *
 * The neighborhood list seeds from events.json's `entry.neighbourhood`
 * field (every distinct value). Saved polygons live in
 * `map-neighbourhoods.json` at the repo root; loading merges saved data
 * onto the seeded list so the UI shows status (defined vs not).
 *
 * Coordinates are normalized to [0, 1] of the map image — same space
 * used by map-locations.json pins.
 */
declare(strict_types=1);

require __DIR__ . '/require-local.php';

$rootDir       = dirname(__DIR__);
$eventsPath    = $rootDir . '/events.json';
$savedPath     = $rootDir . '/map-neighbourhoods.json';
$locationsPath = $rootDir . '/map-locations.json';
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

$events    = readJson($eventsPath,    ['entries' => []]);
$saved     = readJson($savedPath,     ['neighbourhoods' => []]);
$locations = readJson($locationsPath, ['pageWidth' => 1, 'pageHeight' => 1]);

// Unique neighborhoods from events.json (the source of truth for the
// list of names — annotator only adds geometry, not new names).
$names = [];
foreach ($events['entries'] as $e) {
    $n = trim((string)($e['neighbourhood'] ?? ''));
    if ($n !== '') $names[$n] = true;
}
$namesList = array_keys($names);
sort($namesList, SORT_NATURAL | SORT_FLAG_CASE);

// Index saved by name so we can merge polygons in.
$savedByName = [];
foreach ($saved['neighbourhoods'] ?? [] as $n) {
    if (!empty($n['name'])) $savedByName[$n['name']] = $n;
}

$merged = [];
foreach ($namesList as $name) {
    if (isset($savedByName[$name])) {
        $merged[] = $savedByName[$name];
        unset($savedByName[$name]);
    } else {
        $merged[] = ['name' => $name, 'points' => []];
    }
}
// Any leftover saved neighborhoods not in events.json (manually added,
// or names that disappeared from the spreadsheet) — keep them too so we
// don't silently lose work.
foreach ($savedByName as $name => $n) $merged[] = $n;

$mapImgUrl = '../map.png';
if (file_exists($mapPngPath)) {
    $mapImgUrl .= '?v=' . substr((string) md5_file($mapPngPath), 0, 8);
}

$initial = [
    'pageWidth'      => $locations['pageWidth']  ?? ($saved['pageWidth']  ?? 1),
    'pageHeight'     => $locations['pageHeight'] ?? ($saved['pageHeight'] ?? 1),
    'neighbourhoods' => $merged,
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Otherworld — Neighborhood annotator</title>
  <style>
    :root {
      --bg: #0f1a1f;
      --panel: #17252b;
      --panel-2: #1d2f37;
      --line: #2a4048;
      --ink: #e7eef0;
      --ink-dim: #8aa0a8;
      --ink-mid: #b9c7cd;
      --accent: #ffd166;
      --accent-dim: #b58a2f;
      --selected: #ffe66d;
      --danger: #e35d6a;
      --ok: #6cdf8e;
      --pending: #ff7ab0;
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
    .sb-head .save-status.dirty { color: var(--accent); }
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
    .sb-tools select, .sb-tools button {
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
      background: var(--accent);
      color: #2a1f00;
      border-color: var(--accent);
      font-weight: 600;
    }
    .sb-tools button.primary:hover { filter: brightness(1.1); }
    .sb-tools button.primary:disabled { background: var(--ink-dim); border-color: var(--ink-dim); color: var(--panel); }
    .sb-list {
      flex: 1;
      overflow-y: auto;
      padding: 4px 0;
    }
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
    .sb-item:hover { background: var(--panel-2); }
    .sb-item.selected {
      background: var(--panel-2);
      border-left-color: var(--selected);
    }
    .sb-item .dot {
      width: 10px; height: 10px;
      border-radius: 2px;
      background: var(--ink-dim);
      opacity: 0.35;
    }
    .sb-item.defined .dot { background: var(--ok); opacity: 1; }
    .sb-item.partial .dot { background: var(--pending); opacity: 1; }
    .sb-item .name {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-weight: 500;
      letter-spacing: 0.02em;
      text-transform: uppercase;
    }
    .sb-item .pts {
      font-size: 10px;
      color: var(--ink-dim);
      font-family: ui-monospace, Menlo, monospace;
    }
    .sb-item .rm-btn {
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
    .sb-item:hover .rm-btn,
    .sb-item.selected .rm-btn { opacity: 0.7; }
    .sb-item .rm-btn:hover {
      opacity: 1;
      background: rgba(227, 93, 106, 0.18);
      color: var(--danger);
    }

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

    /* SVG overlay sits on top of the map at exactly the same pixel size
       and inherits the same transform via being inside .canvas. */
    .canvas .overlay {
      position: absolute;
      top: 0; left: 0;
      pointer-events: none;
      overflow: visible;
    }
    .overlay polygon {
      fill: rgba(108, 223, 142, 0.18);
      stroke: rgba(108, 223, 142, 0.85);
      stroke-width: 6;
      vector-effect: non-scaling-stroke;
      pointer-events: auto;
      cursor: pointer;
    }
    .overlay polygon.selected {
      fill: rgba(255, 230, 109, 0.32);
      stroke: var(--selected);
      stroke-width: 8;
    }
    .overlay polygon.partial {
      fill: none;
      stroke: rgba(255, 122, 176, 0.85);
      stroke-dasharray: 12 8;
      stroke-width: 6;
    }
    .overlay text {
      fill: #fff;
      text-anchor: middle;
      dominant-baseline: middle;
      font: 700 28px/1 -apple-system, "Inter", system-ui, sans-serif;
      letter-spacing: 0.06em;
      paint-order: stroke;
      stroke: rgba(0,0,0,0.75);
      stroke-width: 5;
      pointer-events: none;
    }
    .overlay circle.corner {
      fill: var(--selected);
      stroke: #0f1a1f;
      stroke-width: 3;
      cursor: grab;
      pointer-events: auto;
    }
    .overlay circle.corner:hover { r: 14; }
    .overlay circle.pending-corner {
      fill: var(--pending);
      stroke: #0f1a1f;
      stroke-width: 3;
      pointer-events: none;
    }

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
    .toolbar button {
      background: transparent;
      color: var(--ink);
      border: 0;
      padding: 4px 8px;
      font: inherit;
      cursor: pointer;
      border-radius: 4px;
    }
    .toolbar button:hover { background: var(--panel-2); }
    .toolbar .sep { width: 1px; align-self: stretch; background: var(--line); margin: 0 2px; }
    .toolbar .zoom { font-family: ui-monospace, Menlo, monospace; font-size: 11px; color: var(--ink-dim); min-width: 38px; text-align: center; }

    .status-overlay {
      position: absolute;
      top: 12px; right: 12px;
      background: rgba(15, 26, 31, 0.92);
      backdrop-filter: blur(6px);
      padding: 8px 12px;
      border-radius: 8px;
      border: 1px solid var(--line);
      font-size: 12px;
      color: var(--ink-mid);
      z-index: 10;
      max-width: 300px;
    }
    .status-overlay .selected-name {
      font-weight: 700;
      color: var(--selected);
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .status-overlay .progress {
      font-family: ui-monospace, Menlo, monospace;
      color: var(--pending);
    }
    .status-overlay button.clear {
      margin-top: 6px;
      background: rgba(227, 93, 106, 0.18);
      color: var(--danger);
      border: 1px solid rgba(227, 93, 106, 0.45);
      padding: 3px 8px;
      font: inherit;
      font-size: 11px;
      border-radius: 4px;
      cursor: pointer;
    }
    .status-overlay button.clear:hover { background: rgba(227, 93, 106, 0.32); }

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
      max-width: 540px;
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
      <h1>Neighborhood annotator</h1>
      <div class="stats" id="stats">loading…</div>
      <div class="save-status" id="saveStatus"></div>
      <div class="sb-tools">
        <input type="search" id="filter" placeholder="Search…">
        <select id="statusFilter">
          <option value="all">All</option>
          <option value="undefined">Undefined only</option>
          <option value="partial">Partial only</option>
          <option value="defined">Defined only</option>
        </select>
        <button id="saveBtn" class="primary" disabled>Save to server</button>
      </div>
    </div>
    <div class="sb-list" id="list"></div>
  </aside>
  <main id="main">
    <div class="toolbar">
      <button id="zoomOut">−</button>
      <span class="zoom" id="zoomLabel">100%</span>
      <button id="zoomIn">+</button>
      <button id="zoomFit">Fit</button>
      <button id="zoom1">1:1</button>
    </div>
    <div class="status-overlay" id="statusOverlay" style="display:none">
      <div><span class="selected-name" id="selName"></span></div>
      <div class="progress" id="selProgress"></div>
      <button class="clear" id="clearCorners">Clear corners</button>
    </div>
    <div class="canvas" id="canvas">
      <img id="mapImg" alt="map" src="<?= htmlspecialchars($mapImgUrl, ENT_QUOTES) ?>">
      <svg class="overlay" id="overlay" preserveAspectRatio="none"></svg>
    </div>
    <div class="help">
      Pick a neighborhood from the sidebar, then click four corners on the map to define its quad. Drag corners to refine.
      <kbd>Esc</kbd> deselect. <kbd>Del</kbd> clear corners. <kbd>⌘Z</kbd>/<kbd>Ctrl+Z</kbd> undo.
      <kbd>Space</kbd>+drag to pan, scroll to zoom. Autosaves every 8s; <kbd>⌘S</kbd> saves now.
    </div>
  </main>
</div>

<script>
window.INITIAL = <?= json_encode($initial, $jsonFlags) ?>;
</script>
<script>
const SAVE_URL    = 'save-neighborhoods.php';
const AUTOSAVE_MS = 8000;
const UNDO_MAX    = 50;
const MAX_CORNERS = 4;

const state = {
  img: { w: 0, h: 0 },
  zoom: 1,
  pan: { x: 0, y: 0 },
  list: [],                  // [{ name, points: [{x,y}, ...], confirmed }]
  byName: new Map(),
  selectedName: null,
  draggingCorner: null,      // { name, idx }
  panning: false,
  panStart: null,
  spaceDown: false,
  justPanned: false,
  dirty: false,
  saving: false,
  lastSavedAt: null,
  undoStack: [],
};

const els = {
  main: document.getElementById('main'),
  canvas: document.getElementById('canvas'),
  img: document.getElementById('mapImg'),
  overlay: document.getElementById('overlay'),
  list: document.getElementById('list'),
  stats: document.getElementById('stats'),
  saveStatus: document.getElementById('saveStatus'),
  filter: document.getElementById('filter'),
  statusFilter: document.getElementById('statusFilter'),
  saveBtn: document.getElementById('saveBtn'),
  zoomIn: document.getElementById('zoomIn'),
  zoomOut: document.getElementById('zoomOut'),
  zoomFit: document.getElementById('zoomFit'),
  zoom1: document.getElementById('zoom1'),
  zoomLabel: document.getElementById('zoomLabel'),
  statusOverlay: document.getElementById('statusOverlay'),
  selName: document.getElementById('selName'),
  selProgress: document.getElementById('selProgress'),
  clearCorners: document.getElementById('clearCorners'),
};

function init() {
  state.list = (INITIAL.neighbourhoods || []).map(n => ({
    name: String(n.name),
    points: Array.isArray(n.points) ? n.points.map(p => ({ x: +p.x, y: +p.y })) : [],
    confirmed: !!n.confirmed,
  }));
  state.list.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
  state.byName = new Map(state.list.map(n => [n.name, n]));

  if (els.img.complete && els.img.naturalWidth) onImageReady();
  else els.img.addEventListener('load', onImageReady);

  renderList();
  updateStats();
  updateSaveStatus();
  updateStatusOverlay();
  bindEvents();
  startAutosave();
}

function onImageReady() {
  state.img.w = els.img.naturalWidth;
  state.img.h = els.img.naturalHeight;
  els.overlay.setAttribute('viewBox', `0 0 ${state.img.w} ${state.img.h}`);
  els.overlay.setAttribute('width',  state.img.w);
  els.overlay.setAttribute('height', state.img.h);
  els.overlay.style.width  = state.img.w + 'px';
  els.overlay.style.height = state.img.h + 'px';
  fitToScreen();
  renderOverlay();
}

// ---------- undo ----------
function snapshot() {
  return state.list.map(n => ({
    name: n.name,
    points: n.points.map(p => ({ x: p.x, y: p.y })),
    confirmed: n.confirmed,
  }));
}
function pushUndo() {
  state.undoStack.push(snapshot());
  if (state.undoStack.length > UNDO_MAX) state.undoStack.shift();
}
function undo() {
  if (!state.undoStack.length) { flashStatus('nothing to undo'); return; }
  const snap = state.undoStack.pop();
  state.list = snap;
  state.byName = new Map(state.list.map(n => [n.name, n]));
  markDirty();
  renderList();
  renderOverlay();
  updateStats();
  updateStatusOverlay();
  flashStatus(state.undoStack.length ? `undid (${state.undoStack.length} more)` : 'undid');
}
function flashStatus(text) {
  updateSaveStatus({ kind: 'dirty', text });
  setTimeout(updateSaveStatus, 1200);
}

function markDirty() { state.dirty = true; updateSaveStatus(); }

function updateStats() {
  let defined = 0, partial = 0;
  for (const n of state.list) {
    if (n.points.length >= MAX_CORNERS) defined++;
    else if (n.points.length > 0)       partial++;
  }
  const undef = state.list.length - defined - partial;
  els.stats.textContent =
    `${defined} defined · ${partial} partial · ${undef} undefined (${state.list.length} total)`;
}

function updateSaveStatus(msg) {
  els.saveBtn.disabled = state.saving || !state.dirty;
  els.saveStatus.classList.remove('dirty', 'saved', 'error');
  if (msg && msg.kind) {
    els.saveStatus.classList.add(msg.kind);
    els.saveStatus.textContent = msg.text;
    return;
  }
  if (state.saving) els.saveStatus.textContent = 'saving…';
  else if (state.dirty) {
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
  return Math.round(m / 60) + 'h ago';
}

function statusOf(n) {
  if (n.points.length >= MAX_CORNERS) return 'defined';
  if (n.points.length > 0) return 'partial';
  return 'undefined';
}

function entryMatchesFilter(n) {
  const q = els.filter.value.trim().toLowerCase();
  if (q && !n.name.toLowerCase().includes(q)) return false;
  const f = els.statusFilter.value;
  if (f !== 'all' && statusOf(n) !== f) return false;
  return true;
}

function renderList() {
  els.list.innerHTML = '';
  for (const n of state.list) {
    if (!entryMatchesFilter(n)) continue;
    const row = document.createElement('div');
    row.className = 'sb-item ' + statusOf(n);
    if (n.name === state.selectedName) row.classList.add('selected');
    row.dataset.name = n.name;
    row.innerHTML = `
      <div class="dot"></div>
      <div class="name">${escapeHtml(n.name)}</div>
      <div class="pts">${n.points.length}/${MAX_CORNERS}</div>
      ${n.points.length ? `<button class="rm-btn" title="Clear corners">×</button>` : '<span></span>'}
    `;
    row.addEventListener('click', ev => {
      if (ev.target.closest('.rm-btn')) return;
      selectEntry(n.name);
    });
    const rm = row.querySelector('.rm-btn');
    if (rm) rm.addEventListener('click', ev => {
      ev.stopPropagation();
      clearCorners(n.name);
    });
    els.list.appendChild(row);
  }
}

function selectEntry(name, opts) {
  opts = opts || {};
  state.selectedName = name;
  els.main.classList.toggle('no-selection', !name);
  if (name && opts.fromMap) {
    els.filter.value = name;
    els.statusFilter.value = 'all';
  }
  renderList();
  renderOverlay();
  updateStatusOverlay();
  if (name) {
    const row = els.list.querySelector('.sb-item.selected');
    if (row) row.scrollIntoView({ block: 'center', behavior: 'instant' });
  }
}

function clearCorners(name) {
  const n = state.byName.get(name);
  if (!n || !n.points.length) return;
  if (!confirm(`Clear corners for "${name}"?`)) return;
  pushUndo();
  n.points = [];
  n.confirmed = false;
  markDirty();
  renderList();
  renderOverlay();
  updateStats();
  updateStatusOverlay();
}

function escapeHtml(s) { return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

function updateStatusOverlay() {
  if (!state.selectedName) { els.statusOverlay.style.display = 'none'; return; }
  const n = state.byName.get(state.selectedName);
  if (!n) { els.statusOverlay.style.display = 'none'; return; }
  els.statusOverlay.style.display = '';
  els.selName.textContent = n.name;
  const left = MAX_CORNERS - n.points.length;
  els.selProgress.textContent = n.points.length >= MAX_CORNERS
    ? `${MAX_CORNERS}/${MAX_CORNERS} corners placed — drag any corner to refine`
    : `${n.points.length}/${MAX_CORNERS} corners placed — click on the map to add ${left} more`;
  els.clearCorners.style.display = n.points.length ? '' : 'none';
}

function renderOverlay() {
  if (!state.img.w) return;
  const svg = els.overlay;
  let html = '';
  for (const n of state.list) {
    if (!n.points || !n.points.length) continue;
    const pts = n.points.map(p => `${p.x * state.img.w},${p.y * state.img.h}`).join(' ');
    const isSelected = n.name === state.selectedName;
    const status = statusOf(n);
    let cls = 'region';
    if (isSelected)              cls += ' selected';
    else if (status === 'partial') cls += ' partial';
    html += `<polygon points="${pts}" class="${cls}" data-name="${escapeHtml(n.name)}"/>`;
    // label at centroid (only on >= 3 points so it isn't dangling near
    // a single corner mid-drawing)
    if (n.points.length >= 3) {
      const cx = n.points.reduce((s, p) => s + p.x, 0) / n.points.length * state.img.w;
      const cy = n.points.reduce((s, p) => s + p.y, 0) / n.points.length * state.img.h;
      html += `<text x="${cx}" y="${cy}">${escapeHtml(n.name)}</text>`;
    }
    // Draggable corners only on the selected neighborhood — too noisy
    // otherwise with 23 polygons on the map.
    if (isSelected) {
      for (let i = 0; i < n.points.length; i++) {
        const cls = (n.points.length < MAX_CORNERS) ? 'pending-corner' : 'corner';
        html += `<circle cx="${n.points[i].x*state.img.w}" cy="${n.points[i].y*state.img.h}" r="10" class="${cls}" data-name="${escapeHtml(n.name)}" data-idx="${i}"/>`;
      }
    }
  }
  svg.innerHTML = html;
  wireOverlayEvents();
}

function wireOverlayEvents() {
  for (const poly of els.overlay.querySelectorAll('polygon')) {
    poly.addEventListener('click', e => {
      e.stopPropagation();
      selectEntry(poly.dataset.name, { fromMap: true });
    });
  }
  for (const c of els.overlay.querySelectorAll('circle.corner')) {
    c.addEventListener('mousedown', e => startCornerDrag(e, c.dataset.name, +c.dataset.idx));
    c.addEventListener('contextmenu', e => {
      e.preventDefault();
      removeCorner(c.dataset.name, +c.dataset.idx);
    });
  }
}

function startCornerDrag(e, name, idx) {
  e.preventDefault();
  e.stopPropagation();
  pushUndo();
  state.draggingCorner = { name, idx };
  els.main.classList.add('dragging');
}

function removeCorner(name, idx) {
  const n = state.byName.get(name);
  if (!n) return;
  pushUndo();
  n.points.splice(idx, 1);
  if (!n.points.length) n.confirmed = false;
  markDirty();
  renderOverlay();
  renderList();
  updateStats();
  updateStatusOverlay();
}

// ---------- pan / zoom ----------
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
function setZoom(z, ax, ay) {
  z = Math.max(0.1, Math.min(8, z));
  const rect = els.main.getBoundingClientRect();
  ax = (ax ?? rect.width / 2) - rect.left;
  ay = (ay ?? rect.height / 2) - rect.top;
  const imgX = (ax - state.pan.x) / state.zoom;
  const imgY = (ay - state.pan.y) / state.zoom;
  state.zoom = z;
  state.pan.x = ax - imgX * state.zoom;
  state.pan.y = ay - imgY * state.zoom;
  setTransform();
}

// Convert a mouse-in-main coordinate to normalized image-space (0-1).
function clientToImageNorm(clientX, clientY) {
  const rect = els.main.getBoundingClientRect();
  const ax = clientX - rect.left;
  const ay = clientY - rect.top;
  const imgX = (ax - state.pan.x) / state.zoom;
  const imgY = (ay - state.pan.y) / state.zoom;
  return { x: imgX / state.img.w, y: imgY / state.img.h };
}

function placeCornerAt(clientX, clientY) {
  if (!state.selectedName) return;
  const n = state.byName.get(state.selectedName);
  if (!n) return;
  if (n.points.length >= MAX_CORNERS) return; // already full — drag instead
  const p = clientToImageNorm(clientX, clientY);
  if (p.x < 0 || p.x > 1 || p.y < 0 || p.y > 1) return;
  pushUndo();
  n.points.push({ x: round(p.x), y: round(p.y) });
  if (n.points.length === MAX_CORNERS) n.confirmed = true;
  markDirty();
  renderOverlay();
  renderList();
  updateStats();
  updateStatusOverlay();
}

function round(v) { return Math.round(v * 10000) / 10000; }

function bindEvents() {
  els.filter.addEventListener('input', renderList);
  els.statusFilter.addEventListener('change', renderList);
  els.saveBtn.addEventListener('click', saveToServer);
  els.zoomIn.addEventListener('click', () => setZoom(state.zoom * 1.25));
  els.zoomOut.addEventListener('click', () => setZoom(state.zoom / 1.25));
  els.zoomFit.addEventListener('click', fitToScreen);
  els.zoom1.addEventListener('click', () => setZoom(1));
  els.clearCorners.addEventListener('click', () => state.selectedName && clearCorners(state.selectedName));

  els.main.addEventListener('click', e => {
    if (e.target.closest('.toolbar')) return;
    if (e.target.closest('.help')) return;
    if (e.target.closest('.status-overlay')) return;
    if (e.target.closest('polygon')) return;
    if (e.target.closest('circle')) return;
    if (state.spaceDown || state.justPanned) return;
    placeCornerAt(e.clientX, e.clientY);
  });

  els.main.addEventListener('mousemove', e => {
    if (state.draggingCorner) {
      const { name, idx } = state.draggingCorner;
      const n = state.byName.get(name);
      if (!n || !n.points[idx]) return;
      const p = clientToImageNorm(e.clientX, e.clientY);
      n.points[idx] = { x: round(Math.max(0, Math.min(1, p.x))), y: round(Math.max(0, Math.min(1, p.y))) };
      markDirty();
      renderOverlay();
    } else if (state.panning) {
      state.pan.x = e.clientX - state.panStart.x;
      state.pan.y = e.clientY - state.panStart.y;
      setTransform();
    }
  });
  els.main.addEventListener('mouseup', () => {
    if (state.draggingCorner) {
      state.draggingCorner = null;
      els.main.classList.remove('dragging');
      renderList();
      updateStatusOverlay();
    }
    if (state.panning) state.justPanned = true;
    state.panning = false;
  });
  els.main.addEventListener('mousedown', e => {
    state.justPanned = false;
    if (e.target.closest('polygon') || e.target.closest('circle')) return;
    if (e.button === 1 || state.spaceDown) {
      e.preventDefault();
      state.panning = true;
      state.panStart = { x: e.clientX - state.pan.x, y: e.clientY - state.pan.y };
      els.main.classList.add('dragging');
    }
  });
  els.main.addEventListener('wheel', e => {
    e.preventDefault();
    setZoom(state.zoom * Math.exp(-e.deltaY * 0.0015), e.clientX, e.clientY);
  }, { passive: false });

  window.addEventListener('keydown', e => {
    const inField = document.activeElement && ['INPUT','SELECT','TEXTAREA'].includes(document.activeElement.tagName);
    if (e.key === ' ' && !inField) {
      state.spaceDown = true;
      els.main.style.cursor = 'grab';
      e.preventDefault();
    }
    if (e.key === 'Escape') {
      selectEntry(null);
    }
    if ((e.key === 'Delete' || e.key === 'Backspace') && !inField && state.selectedName) {
      const n = state.byName.get(state.selectedName);
      if (n && n.points.length) {
        e.preventDefault();
        clearCorners(state.selectedName);
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
  setInterval(() => { if (!state.dirty && !state.saving) updateSaveStatus(); }, 5000);
}

function startAutosave() {
  setInterval(() => {
    if (state.dirty && !state.saving) saveToServer();
  }, AUTOSAVE_MS);
}

function buildPayload() {
  const arr = state.list.map(n => {
    const out = { name: n.name };
    if (n.points.length) out.points = n.points.map(p => ({ x: p.x, y: p.y }));
    if (n.confirmed)     out.confirmed = true;
    return out;
  });
  return { neighbourhoods: arr };
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
    if (!resp.ok || !body.ok) throw new Error(body.error || ('HTTP ' + resp.status));
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

init();
</script>
</body>
</html>
