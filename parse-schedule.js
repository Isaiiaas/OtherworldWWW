#!/usr/bin/env node
/**
 * Parse Otherworld 2026 schedule PDF into structured JSON.
 *
 * Detection strategy:
 *  - `pdftotext -bbox-layout` gives us text blocks with coordinates.
 *  - Each wide page is rendered with `pdftoppm` so we can sample pixel colors.
 *  - Entry name boxes (camps, art installations, mutant vehicles, sound stages)
 *    share a dark-green background — that's the primary signal.
 *  - "Section banners" (ART INSTALLATIONS, MUTANT VEHICULE, SOUND STAGES) are
 *    rendered with a noticeably larger font (h ≥ 35) and switch the active
 *    section type for the entries that follow.
 *  - Events are blocks containing a "Day  HH:MM - HH:MM" tail. Multi-day
 *    listings ("Fri - Sat - Sun 13:00 - 03:00") are expanded into one entry
 *    per day.
 */

const fs = require("fs");
const { execFileSync } = require("child_process");
const path = require("path");
const { PNG } = require("pngjs");

const PDF_PATH = path.resolve(__dirname, "www2026.pdf");
const OUT_PATH = path.resolve(__dirname, "events.json");
const OUT_DATA_JS = path.resolve(__dirname, "data.js");
const TMP_HTML = "/tmp/www2026.bbox.html";
const TMP_RENDER_DIR = "/tmp/www2026_render";
const RENDER_DPI = 150;

const ENTRY_HEADER_HEIGHT_MIN = 22;     // any header (entry or section banner)
const SECTION_BANNER_HEIGHT_MIN = 35;   // ART INSTALLATIONS / MUTANT VEHICULE / SOUND STAGES
const PAGE_PT_WIDTH_WIDE = 1224;

// Observed xMin values cluster around 16, 320, 620, 921. Boundaries pick
// midpoints so a block at 921 lands in column 3, not column 2.
const COLUMN_BOUNDS_WIDE = [
  { min: 0, max: 300 },
  { min: 300, max: 610 },
  { min: 610, max: 820 },
  { min: 820, max: 1300 },
];

// Page 2 is the welcome/guiding-principles page — it uses the same green
// styling as camp boxes but isn't part of the schedule. Skip it entirely.
const NON_SCHEDULE_PAGE_INDICES = new Set([1]);

// Section banners we want to recognize. Matching is case-insensitive,
// punctuation-tolerant. "CONCERNING MOISTURE" is a content panel, not a
// section divider, so we map it to null and ignore it.
const SECTION_BANNERS = [
  { pattern: /^art\s+installations?$/i, type: "art_installation" },
  { pattern: /^mutant\s+vehicules?$/i,  type: "mutant_vehicle" },     // typo preserved
  { pattern: /^mutant\s+vehicles?$/i,   type: "mutant_vehicle" },
  { pattern: /^sound\s+stages?$/i,      type: "sound_stage" },
  { pattern: /^concerning\s+moisture$/i, type: null },                 // ignore
];

// Entry name boxes have a dark green background. Sampled values cluster
// around R 20–130, G 80–160, B 50–65; some headers (e.g. "Camp Otherbo’r’d")
// have a top portion at B ~85–90. Event boxes are yellow/lime (R 150+,
// G 180+, B 110+). Page 2 "values" use the same green so we also rely on
// other filters (text length, time pattern) to exclude them.
function isCampGreen(r, g, b) {
  return r <= 160 && g >= 70 && g <= 180 && b <= 95 && g >= r - 30 && g > b;
}

const DAYS = {
  monday: "Monday", mon: "Monday",
  tuesday: "Tuesday", tues: "Tuesday", tue: "Tuesday",
  wednesday: "Wednesday", wed: "Wednesday",
  thursday: "Thursday", thurs: "Thursday", thu: "Thursday",
  friday: "Friday", fri: "Friday",
  saturday: "Saturday", sat: "Saturday",
  sunday: "Sunday", sun: "Sunday",
};
const DAY_ORDER = ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"];
const DAY_TOKEN = "(?:Monday|Mon|Tuesday|Tues|Tue|Wednesday|Wed|Thursday|Thurs|Thu|Friday|Fri|Saturday|Sat|Sunday|Sun)";
const TIME_LINE_RE = new RegExp(
  `(${DAY_TOKEN}(?:\\s*[-–]\\s*${DAY_TOKEN})*)\\s+(\\d{1,2}:\\d{2}(?::\\d{2})?)\\s*[-–]\\s*(\\d{1,2}:\\d{2}(?::\\d{2})?)`,
  "i"
);

// ---------- helpers ----------

function decodeEntities(s) {
  return s
    .replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&apos;/g, "'");
}

function cleanText(s) {
  return s.replace(/\s+/g, " ").trim();
}

function joinLineTexts(lines) {
  let out = "";
  for (let i = 0; i < lines.length; i++) {
    const t = lines[i].text;
    if (i === 0) { out = t; continue; }
    // Soft-hyphen joining: "soft-\n hyphen" -> "softhyphen"
    if (/[a-z]-$/.test(out) && /^[a-z]/.test(t)) {
      out = out.replace(/-$/, "") + t;
    } else {
      out = out + " " + t;
    }
  }
  return cleanText(out);
}

function runPdftotext() {
  execFileSync("pdftotext", ["-bbox-layout", PDF_PATH, TMP_HTML]);
}

function renderPages(pageNumbers) {
  fs.mkdirSync(TMP_RENDER_DIR, { recursive: true });
  if (pageNumbers.length === 0) return;
  const first = Math.min(...pageNumbers);
  const last = Math.max(...pageNumbers);
  execFileSync("pdftoppm", [
    "-r", String(RENDER_DPI),
    "-png",
    "-f", String(first),
    "-l", String(last),
    PDF_PATH,
    path.join(TMP_RENDER_DIR, "page"),
  ]);
}

function readPng(pageNumber1Based) {
  const padded = String(pageNumber1Based).padStart(2, "0");
  const filePath = path.join(TMP_RENDER_DIR, `page-${padded}.png`);
  const buf = fs.readFileSync(filePath);
  return PNG.sync.read(buf);
}

// Returns the fraction of pixels in the rectangle that match `isCampGreen`.
// We count rather than average because some headers contain non-green glyph
// strokes (e.g. yellow apostrophes in "Camp Otherbo’r’d") that pull the
// average outside the camp-green range even when most of the box is green.
function campGreenFraction(png, xMin, yMin, xMax, yMax) {
  const scale = png.width / PAGE_PT_WIDTH_WIDE;
  const x0 = Math.max(0, Math.round(xMin * scale));
  const y0 = Math.max(0, Math.round(yMin * scale));
  const x1 = Math.min(png.width - 1, Math.round(xMax * scale));
  const y1 = Math.min(png.height - 1, Math.round(yMax * scale));
  if (x1 <= x0 || y1 <= y0) return 0;
  let green = 0, total = 0;
  for (let y = y0; y <= y1; y++) {
    for (let x = x0; x <= x1; x++) {
      const i = (y * png.width + x) * 4;
      if (isCampGreen(png.data[i], png.data[i + 1], png.data[i + 2])) green++;
      total++;
    }
  }
  return green / total;
}

// ---------- bbox parsing ----------

function parseBbox() {
  const html = fs.readFileSync(TMP_HTML, "utf8");
  const pageRe = /<page width="([0-9.]+)" height="([0-9.]+)">([\s\S]*?)<\/page>/g;
  const pages = [];
  let m;
  while ((m = pageRe.exec(html)) !== null) {
    pages.push({ width: +m[1], height: +m[2], body: m[3] });
  }

  return pages.map((p, idx) => {
    const blocks = [];
    const blockRe = /<block xMin="([0-9.]+)" yMin="([0-9.]+)" xMax="([0-9.]+)" yMax="([0-9.]+)">([\s\S]*?)<\/block>/g;
    let b;
    while ((b = blockRe.exec(p.body)) !== null) {
      const block = {
        xMin: +b[1], yMin: +b[2], xMax: +b[3], yMax: +b[4],
        lines: [],
      };
      const lineRe = /<line xMin="([0-9.]+)" yMin="([0-9.]+)" xMax="([0-9.]+)" yMax="([0-9.]+)">([\s\S]*?)<\/line>/g;
      let l;
      while ((l = lineRe.exec(b[5])) !== null) {
        const text = decodeEntities(l[5].replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim());
        if (!text) continue;
        block.lines.push({
          xMin: +l[1], yMin: +l[2], xMax: +l[3], yMax: +l[4],
          text,
          height: +l[4] - +l[2],
        });
      }
      if (block.lines.length) blocks.push(block);
    }
    return { pageIndex: idx, width: p.width, height: p.height, blocks };
  });
}

function assignColumn(block, pageWidth) {
  if (pageWidth < 800) return 0;
  for (let i = 0; i < COLUMN_BOUNDS_WIDE.length; i++) {
    if (block.xMin >= COLUMN_BOUNDS_WIDE[i].min && block.xMin < COLUMN_BOUNDS_WIDE[i].max) return i;
  }
  return COLUMN_BOUNDS_WIDE.length - 1;
}

// ---------- classification ----------

function blockText(b) {
  return joinLineTexts(b.lines);
}

// Find every time line in a block. Many events list two or more schedule
// lines, e.g.:
//   Thursday 18:00 - 03:00
//   Fri - Sat - Sun 13:00 - 03:00
// Each time line should yield its own event occurrences.
function findAllTimeMatches(block) {
  const matches = [];
  for (let i = 0; i < block.lines.length; i++) {
    // A line can contain multiple non-overlapping time patterns in rare cases
    // (e.g. "There is no free lunch.Friday 13:00 - 14:00"). Use a global scan.
    const re = new RegExp(TIME_LINE_RE.source, "ig");
    let m;
    while ((m = re.exec(block.lines[i].text)) !== null) {
      matches.push({ lineIndex: i, match: m, raw: m[0] });
    }
  }
  return matches;
}

function hasAnyTime(block) {
  for (const line of block.lines) {
    if (TIME_LINE_RE.test(line.text)) return true;
  }
  return false;
}

function isSectionBanner(block) {
  if (!block.lines.length) return null;
  if (block.lines[0].height < SECTION_BANNER_HEIGHT_MIN) return null;
  const txt = blockText(block);
  for (const def of SECTION_BANNERS) {
    if (def.pattern.test(txt)) return { matched: txt, type: def.type };
  }
  return null;
}

function isLikelyHeader(block, pngForPage) {
  if (!block.lines.length) return false;
  if (block.lines[0].height < ENTRY_HEADER_HEIGHT_MIN) return false;
  const text = blockText(block);
  if (TIME_LINE_RE.test(text)) return false;
  if (text.length < 2 || text.length > 120) return false;
  if (!pngForPage) return false;
  return campGreenFraction(pngForPage, block.xMin, block.yMin, block.xMax, block.yMax) >= 0.3;
}

// If a "header" block contains multiple lines, pdftotext sometimes merged
// two adjacent green name boxes. We split by sampling the pixel between
// successive lines: a green pixel = same name (word wrap), a yellow/lighter
// pixel = different boxes stacked.
function splitHeaderBlockIntoNames(block, pngForPage) {
  if (!pngForPage || block.lines.length <= 1) return [blockText(block)];

  const groups = [[block.lines[0]]];
  for (let i = 1; i < block.lines.length; i++) {
    const prev = block.lines[i - 1];
    const curr = block.lines[i];
    const midY = (prev.yMax + curr.yMin) / 2;
    const midX = (block.xMin + block.xMax) / 2;
    const fraction = campGreenFraction(pngForPage, midX - 20, midY - 1, midX + 20, midY + 1);
    if (fraction >= 0.5) {
      groups[groups.length - 1].push(curr);
    } else {
      groups.push([curr]);
    }
  }

  return groups.map(lines => joinLineTexts(lines));
}

// ---------- time normalization ----------

function expandDayTokens(rawDays) {
  const tokens = rawDays.split(/\s*[-–]\s*/).map(t => t.trim().toLowerCase());
  return tokens.map(t => DAYS[t]).filter(Boolean);
}

function normalizeTime(t) {
  const m = t.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
  if (!m) return null;
  const hh = parseInt(m[1], 10);
  const mm = parseInt(m[2], 10);
  if (hh > 23 || mm > 59) return null;
  return `${String(hh).padStart(2, "0")}:${String(mm).padStart(2, "0")}`;
}

function toMinutes(t) {
  const [h, m] = t.split(":").map(Number);
  return h * 60 + m;
}

const FESTIVAL_DAY_START_HOUR = 6;
const FESTIVAL_DAY_START = FESTIVAL_DAY_START_HOUR * 60;

function festivalMinutes(min) {
  // Time-of-day in festival minutes (0 = 06:00, increases through the night).
  return min >= FESTIVAL_DAY_START ? min - FESTIVAL_DAY_START : (24 * 60 - FESTIVAL_DAY_START) + min;
}

function buildTimeFields(startStr, endStr) {
  const startN = normalizeTime(startStr);
  const endN = normalizeTime(endStr);
  if (!startN || !endN) {
    return {
      startTime: startN, endTime: endN,
      durationHours: null, crossesMidnight: null,
      normalizationFlags: ["unparseable_time"],
    };
  }
  const startMin = toMinutes(startN);
  const endMin = toMinutes(endN);
  const startFest = festivalMinutes(startMin);
  const endFest = festivalMinutes(endMin);
  const dayBoundary = (24 - FESTIVAL_DAY_START_HOUR) * 60; // 18*60
  const crossesMidnight = startFest < dayBoundary && endFest >= dayBoundary;

  const flags = [];
  let duration = endFest - startFest;
  if (duration < 0) {
    flags.push("end_before_start_within_festival_day");
    duration += 24 * 60;
  }
  if (duration > 18 * 60) flags.push("suspiciously_long_duration");
  if (duration === 0) flags.push("zero_duration");

  // User hint: "events go until 6am, anything between 6am and something is
  // probably wrong hours." Flag end times that fall inside the morning
  // (06:00–11:00) when the start is sometime in the afternoon/evening — that
  // pattern almost always indicates a submission error.
  if (endMin >= FESTIVAL_DAY_START && endMin < 11 * 60 && startMin >= 12 * 60) {
    flags.push("end_in_morning_after_afternoon_start");
  }

  return {
    startTime: startN,
    endTime: endN,
    durationHours: Math.round((duration / 60) * 100) / 100,
    crossesMidnight,
    normalizationFlags: flags,
  };
}

function splitTitleAndDescription(text) {
  const idx = text.indexOf(":");
  if (idx === -1) return { title: text.trim(), description: "" };
  return {
    title: text.slice(0, idx).trim(),
    description: text.slice(idx + 1).trim(),
  };
}

function buildEventOccurrences(block, allMatches, ownerName, ownerType) {
  // Body = everything that isn't a time-line. Strip the matched time strings
  // from their respective lines and join the remainder.
  const bodyParts = [];
  const stripPerLine = new Map();
  for (const tm of allMatches) {
    const list = stripPerLine.get(tm.lineIndex) || [];
    list.push(tm.raw);
    stripPerLine.set(tm.lineIndex, list);
  }
  for (let i = 0; i < block.lines.length; i++) {
    let txt = block.lines[i].text;
    const strips = stripPerLine.get(i);
    if (strips) {
      for (const s of strips) txt = txt.split(s).join(" ");
      txt = txt.replace(/\s{2,}/g, " ").trim();
    }
    if (txt) bodyParts.push({ text: txt });
  }
  const fullBody = joinLineTexts(bodyParts);
  const { title, description } = splitTitleAndDescription(fullBody);

  const out = [];
  for (const tm of allMatches) {
    const rawDays = tm.match[1];
    const startStr = tm.match[2];
    const endStr = tm.match[3];
    const days = expandDayTokens(rawDays);
    const occurrences = days.length ? days : [null];
    for (const day of occurrences) {
      out.push({
        owner: ownerName,
        ownerType,
        title,
        description,
        day,
        ...buildTimeFields(startStr, endStr),
        rawTimeText: tm.raw,
      });
    }
  }
  return out;
}

function looksLikeTimeOnlyBlock(block) {
  const txt = blockText(block);
  return TIME_LINE_RE.test(txt) && txt.replace(TIME_LINE_RE, "").trim().length < 4;
}

// ---------- main pipeline ----------

function main() {
  console.error("Running pdftotext -bbox-layout...");
  runPdftotext();
  const pages = parseBbox();
  console.error(`Parsed ${pages.length} pages.`);

  const wideOneBased = pages
    .map((p, i) => ({ page: i + 1, wide: p.width >= 800 }))
    .filter(p => p.wide)
    .map(p => p.page);
  console.error(`Rendering wide pages ${wideOneBased[0]}–${wideOneBased[wideOneBased.length - 1]} at ${RENDER_DPI} dpi...`);
  renderPages(wideOneBased);

  const pageImages = {};
  for (const pageNum of wideOneBased) {
    pageImages[pageNum] = readPng(pageNum);
  }

  // Order all blocks across the document in reading order:
  // page -> column (left to right) -> yMin (top to bottom).
  const ordered = [];
  for (const page of pages) {
    if (page.width < 800) continue;
    if (NON_SCHEDULE_PAGE_INDICES.has(page.pageIndex)) continue;
    for (const b of page.blocks) {
      ordered.push({
        block: b,
        column: assignColumn(b, page.width),
        pageIndex: page.pageIndex,
        pageNumber: page.pageIndex + 1,
      });
    }
  }
  ordered.sort((a, b) => {
    if (a.pageIndex !== b.pageIndex) return a.pageIndex - b.pageIndex;
    if (a.column !== b.column) return a.column - b.column;
    return a.block.yMin - b.block.yMin;
  });

  // Merge orphan time-only blocks back into the preceding event/entry block.
  const merged = [];
  for (const item of ordered) {
    const prev = merged[merged.length - 1];
    if (
      prev &&
      prev.pageIndex === item.pageIndex &&
      prev.column === item.column &&
      looksLikeTimeOnlyBlock(item.block) &&
      !hasAnyTime(prev.block)
    ) {
      prev.block.lines.push(...item.block.lines);
      continue;
    }
    merged.push(item);
  }

  // Walk in reading order.
  const entriesByKey = new Map(); // key = `${type}::${name}`
  let currentSectionType = "camp"; // default
  let currentEntry = null;

  function getEntry(name, type) {
    const key = `${type}::${name}`;
    if (!entriesByKey.has(key)) {
      entriesByKey.set(key, { name, type, events: [] });
    }
    return entriesByKey.get(key);
  }

  const unassignedEvents = [];
  const unmatchedHeaders = [];

  for (const item of merged) {
    const blk = item.block;
    const png = pageImages[item.pageNumber];

    // Section banner?
    const banner = isSectionBanner(blk);
    if (banner) {
      if (banner.type) currentSectionType = banner.type;
      currentEntry = null;
      continue;
    }

    // Entry header (green box)?
    if (isLikelyHeader(blk, png)) {
      const names = splitHeaderBlockIntoNames(blk, png);
      // When multiple names were glued together, attribute subsequent events
      // to the LAST name (that's the closest header above the events below).
      for (const n of names) getEntry(n, currentSectionType);
      currentEntry = getEntry(names[names.length - 1], currentSectionType);
      continue;
    }

    // Event block?
    const allMatches = findAllTimeMatches(blk);
    if (allMatches.length) {
      const owner = currentEntry ? currentEntry.name : null;
      const ownerType = currentEntry ? currentEntry.type : currentSectionType;
      const occurrences = buildEventOccurrences(blk, allMatches, owner, ownerType);
      if (currentEntry) currentEntry.events.push(...occurrences);
      else unassignedEvents.push(...occurrences);
      continue;
    }

    // Otherwise: a large-font block that isn't green and isn't an event — skip it.
    // (Page 2 "guiding principles" mostly land here once we filter by color.)
    if (blk.lines[0].height >= ENTRY_HEADER_HEIGHT_MIN) {
      unmatchedHeaders.push({
        page: item.pageNumber,
        text: blockText(blk).slice(0, 80),
      });
    }
  }

  // Same physical place gets listed in multiple sections (e.g. "Ironic Spin"
  // appears in the camp section on page 11 AND in the sound-stages section on
  // page 31). Merge by case-insensitive name. The more-specific type wins.
  const typePriority = { sound_stage: 4, mutant_vehicle: 3, art_installation: 2, camp: 1 };
  const normName = s => s.trim().toLowerCase().replace(/\s+/g, " ");
  const eventKey = ev => `${ev.day}|${ev.startTime}|${ev.endTime}|${ev.title}`;
  const isAllCaps = s => s === s.toUpperCase() && /[A-Z]/.test(s);

  const mergedEntries = new Map();
  for (const entry of entriesByKey.values()) {
    const key = normName(entry.name);
    if (!mergedEntries.has(key)) {
      mergedEntries.set(key, {
        name: entry.name,
        type: entry.type,
        events: entry.events.slice(),
      });
      continue;
    }
    const m = mergedEntries.get(key);
    if ((typePriority[entry.type] || 0) > (typePriority[m.type] || 0)) m.type = entry.type;
    // Prefer mixed-case display name over ALL-CAPS variants
    if (isAllCaps(m.name) && !isAllCaps(entry.name)) m.name = entry.name;
    const seen = new Set(m.events.map(eventKey));
    for (const ev of entry.events) {
      if (seen.has(eventKey(ev))) continue;
      m.events.push(ev);
      seen.add(eventKey(ev));
    }
  }

  // Reconcile event owner/ownerType to the merged entry's single type.
  for (const m of mergedEntries.values()) {
    for (const ev of m.events) {
      ev.owner = m.name;
      ev.ownerType = m.type;
    }
    m.events.sort((a, b) => {
      const da = DAY_ORDER.indexOf(a.day);
      const db = DAY_ORDER.indexOf(b.day);
      if (da !== db) return da - db;
      return toMinutes(a.startTime || "00:00") - toMinutes(b.startTime || "00:00");
    });
  }

  const entries = Array.from(mergedEntries.values()).sort((a, b) => {
    if (a.type !== b.type) {
      // Display order: camps first, then stages, vehicles, art
      const order = { camp: 0, sound_stage: 1, mutant_vehicle: 2, art_installation: 3 };
      return (order[a.type] ?? 9) - (order[b.type] ?? 9);
    }
    return a.name.localeCompare(b.name, undefined, { sensitivity: "base" });
  });

  const counts = entries.reduce((acc, e) => {
    acc[e.type] = (acc[e.type] || 0) + 1;
    acc[`${e.type}_events`] = (acc[`${e.type}_events`] || 0) + e.events.length;
    return acc;
  }, {});

  const out = {
    metadata: {
      source: path.basename(PDF_PATH),
      extractedAt: new Date().toISOString(),
      entryCount: entries.length,
      eventCount: entries.reduce((n, e) => n + e.events.length, 0),
      unassignedEventCount: unassignedEvents.length,
      countsByType: counts,
    },
    entries,
    unassignedEvents,
  };

  fs.writeFileSync(OUT_PATH, JSON.stringify(out, null, 2));
  fs.writeFileSync(
    OUT_DATA_JS,
    `window.OTHERWORLD_DATA = ${JSON.stringify(out)};\n`
  );
  console.error(
    `Wrote ${OUT_PATH} and ${OUT_DATA_JS} — ${entries.length} entries, ${out.metadata.eventCount} event-occurrences.`
  );
  console.error("Counts by type:", counts);
  if (unassignedEvents.length) {
    console.error(`Warning: ${unassignedEvents.length} events had no associated entry.`);
  }
  if (unmatchedHeaders.length) {
    console.error(`Skipped ${unmatchedHeaders.length} large-font blocks (non-green, non-event).`);
  }
}

main();
