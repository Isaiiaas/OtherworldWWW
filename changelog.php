<?php
declare(strict_types=1);

// Lists events that exist in events.json but NOT in the shared spreadsheet.
// Useful for spotting events that were added/edited directly via dashboard
// claims and haven't been mirrored back to the canonical sheet.

$ROOT        = __DIR__;
$EVENTS_FILE = $ROOT . '/events.json';

$SHEET_ID  = '1o9Ue218Yx8mMa9OGyPfd66NoofYI3O1ewkN8NB-qnVc';
$SHEET_GID = '1785212198';
$SHEET_URL = "https://docs.google.com/spreadsheets/d/$SHEET_ID/export?format=csv&gid=$SHEET_GID";
$SHEET_VIEW_URL = "https://docs.google.com/spreadsheets/d/$SHEET_ID/edit?gid=$SHEET_GID#gid=$SHEET_GID";

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pretty_type(string $t): string {
    $map = [
        'camp'             => 'camp',
        'sound_stage'      => 'sound stage',
        'art_installation' => 'art installation',
        'mutant_vehicle'   => 'mutant vehicle',
    ];
    if ($t === '') return '';
    return $map[$t] ?? str_replace('_', ' ', $t);
}

/**
 * Mirror of admin/reconcile-sheet.php::canonical(). Kept in sync manually —
 * if reconcile changes its rules, update here too so the comparison stays
 * meaningful.
 */
function canonical(string $name): string {
    $s = trim($name);
    if ($s === '') return '';
    if (function_exists('transliterator_transliterate')) {
        $t = @transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
        if (is_string($t)) $s = $t;
        else $s = strtolower($s);
    } else {
        $s = strtolower($s);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = $t;
        }
    }
    $s = preg_replace('/^the\s+/', '', $s);
    $s = preg_replace('/,?\s*the\s*$/', '', $s);
    $s = preg_replace('/\s+camp\s*$/', '', $s);
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return (string)$s;
}

/**
 * Loads camp-aliases.json and returns [canonical(typo) => canonical-name]
 * so misspelled names match the canonical version when comparing sides.
 */
function load_alias_map(string $path): array {
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    $aliases = (is_array($data) && isset($data['aliases']) && is_array($data['aliases']))
        ? $data['aliases'] : [];
    $out = [];
    foreach ($aliases as $from => $to) {
        $k = canonical((string)$from);
        if ($k !== '' && is_string($to)) $out[$k] = (string)$to;
    }
    return $out;
}

/**
 * Like canonical() but tuned for free-text fields (event titles): lowercases,
 * strips accents, removes ALL non-alphanumerics. Does NOT strip leading
 * "The "/trailing " Camp" since those rules are camp-name-specific. Used
 * so titles like "Dommy UMAMI Nood Takeover" and "Dommy U-MAMI's Nood
 * Takeover" — same event, sheet vs dashboard punctuation drift — match.
 */
function canonical_text(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    if (function_exists('transliterator_transliterate')) {
        $t = @transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
        if (is_string($t)) $s = $t;
        else $s = strtolower($s);
    } else {
        $s = strtolower($s);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = $t;
        }
    }
    // Fold `&` <-> `and` and possessive `'s` so "Get Nailed & Stamped" /
    // "Get Nailed and Stamped" and "Dommy UMAMI Nood Takeover" /
    // "Dommy U-MAMI's Nood Takeover" produce the same key. Order matters:
    // expand `&` to ` and ` first, drop possessive `'s` next, then drop the
    // bare conjunction `and` (which now eats both originals and conversions).
    $s = str_replace('&', ' and ', $s);
    $s = preg_replace("/'s\b/", '', $s);
    $s = preg_replace('/\band\b/', '', $s);
    return (string)preg_replace('/[^a-z0-9]+/', '', $s);
}

/**
 * Normalise day-of-week so "Mon"/"Monday"/"mon" all hash to the same key.
 * Leaves unrecognised / empty values as the lowercased-trimmed input so we
 * don't silently swallow typos.
 */
function canonical_day(string $d): string {
    $d = strtolower(trim($d));
    static $map = [
        'mon' => 'monday',    'monday'    => 'monday',
        'tue' => 'tuesday',   'tues'      => 'tuesday',  'tuesday'   => 'tuesday',
        'wed' => 'wednesday', 'weds'      => 'wednesday','wednesday' => 'wednesday',
        'thu' => 'thursday',  'thur'      => 'thursday', 'thurs'     => 'thursday', 'thursday' => 'thursday',
        'fri' => 'friday',    'friday'    => 'friday',
        'sat' => 'saturday',  'saturday'  => 'saturday',
        'sun' => 'sunday',    'sunday'    => 'sunday',
    ];
    return $map[$d] ?? $d;
}

function event_key(string $camp, string $title, string $day, array $aliasMap): string {
    $c = canonical($camp);
    if (isset($aliasMap[$c])) $c = canonical($aliasMap[$c]);
    return $c . '|' . canonical_text($title) . '|' . canonical_day($day);
}

/**
 * Fetch the sheet and build:
 *   - $keys: set of (camp,title,day) canonical keys for the exact-match check
 *   - $rows: a list of per-row {canonCamp, canonText, canonDay, canonDesc,
 *            rawTitle, rawCamp, rawDay} dicts. Returned so Phase 2 can build
 *            secondary indices (by camp+day, by title-only) for the fuzzy
 *            renames / cross-camp-guest detection without re-fetching CSV.
 * Returns [keys, rows, error] — first two are empty if the fetch failed.
 */
function fetch_sheet_keys(string $url, array $aliasMap): array {
    $ctx = stream_context_create([
        'http' => [
            'timeout'         => 20,
            'follow_location' => 1,
            'ignore_errors'   => true,
            'header'          => "Accept: text/csv\r\n",
        ],
    ]);
    $csv = @file_get_contents($url, false, $ctx);
    if ($csv === false) {
        return [[], [], 'Could not reach the spreadsheet (network error).'];
    }
    $statusLine = $http_response_header[0] ?? '';
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m) && (int)$m[1] >= 400) {
        return [[], [], 'Spreadsheet fetch returned HTTP ' . $m[1] . '.'];
    }
    $trimmed = ltrim($csv);
    if ($trimmed === '') {
        return [[], [], 'Spreadsheet response was empty.'];
    }
    if (stripos($trimmed, '<!doctype') === 0 || stripos($trimmed, '<html') === 0) {
        return [[], [], 'Spreadsheet returned HTML, not CSV (sheet likely not public).'];
    }

    $rows = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $rows[] = $r;
    }
    fclose($fh);

    // Find the header row (matches reconcile-sheet.php's heuristic).
    $headerRowIdx = null;
    foreach ($rows as $i => $r) {
        if (($r[2] ?? '') === 'Day' && ($r[3] ?? '') === 'Start Time') {
            $headerRowIdx = $i;
            break;
        }
    }
    if ($headerRowIdx === null) {
        return [[], [], 'Could not find a header row in the spreadsheet.'];
    }
    $headerIndex = [];
    foreach ($rows[$headerRowIdx] as $i => $hname) {
        $headerIndex[trim((string)$hname)] = $i;
    }
    foreach (['Day', 'Camp', 'Event Name'] as $required) {
        if (!isset($headerIndex[$required])) {
            return [[], [], "Spreadsheet is missing the '$required' column."];
        }
    }
    // 'Description of Event' is optional — older snapshots may be missing it.
    $descIdx = $headerIndex['Description of Event'] ?? null;

    $keys     = [];
    $sheetRows = [];
    foreach (array_slice($rows, $headerRowIdx + 1) as $r) {
        $camp  = trim((string)($r[$headerIndex['Camp']] ?? ''));
        $title = trim((string)($r[$headerIndex['Event Name']] ?? ''));
        $day   = trim((string)($r[$headerIndex['Day']] ?? ''));
        $desc  = $descIdx !== null ? trim((string)($r[$descIdx] ?? '')) : '';
        if ($camp === '' || $title === '') continue;
        // Resolve camp aliases up front so both indices key on the canonical
        // target. Without this an aliased typo camp would never match the
        // primary's events.json side under the same-camp-same-day check.
        $cCamp = canonical($camp);
        if (isset($aliasMap[$cCamp])) $cCamp = canonical($aliasMap[$cCamp]);
        $cText = canonical_text($title);
        $cDay  = canonical_day($day);
        $cDesc = $desc !== '' ? canonical_text($desc) : '';
        $keys[$cCamp . '|' . $cText . '|' . $cDay] = true;
        $sheetRows[] = [
            'canonCamp' => $cCamp,
            'canonText' => $cText,
            'canonDay'  => $cDay,
            'canonDesc' => $cDesc,
            'rawTitle'  => $title,
            'rawCamp'   => $camp,
            'rawDay'    => $day,
        ];
    }
    return [$keys, $sheetRows, null];
}

// ── Load events.json ──────────────────────────────────────────────────────
$entries = [];
$eventsError = null;
if (!is_file($EVENTS_FILE)) {
    $eventsError = 'events.json not found.';
} else {
    $raw = @file_get_contents($EVENTS_FILE);
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
        $eventsError = 'events.json is missing or malformed.';
    } else {
        $entries = $data['entries'];
    }
}

// ── Fetch sheet keys ──────────────────────────────────────────────────────
$aliasMap = load_alias_map($ROOT . '/camp-aliases.json');
[$sheetKeys, $sheetRows, $sheetError] = fetch_sheet_keys($SHEET_URL, $aliasMap);

// ── Dedup entries in memory (canonical name + alias resolution) ───────────
// Mirrors scripts/dedupe-events.php so the list reflects the post-dedup view
// even before the script has been run on this events.json. Within a group:
//   - Primary = claimed wins; else lowest array index.
//   - Display name rewritten to the alias target if the primary is itself an
//     aliased / typo'd variant.
//   - Events merged with first-wins on (title, day, startTime).
//   - Claimed flag = OR of every entry in the group.
$groups = []; // canonicalKey => ['entry'=>array, 'index'=>int]
foreach ($entries as $i => $entry) {
    $rawCanon = canonical((string)($entry['name'] ?? ''));
    $key = isset($aliasMap[$rawCanon]) ? canonical($aliasMap[$rawCanon]) : $rawCanon;
    if (!isset($groups[$key])) {
        $groups[$key] = [];
    }
    $groups[$key][] = ['entry' => $entry, 'index' => $i];
}
$dedupedEntries = [];
foreach ($groups as $list) {
    usort($list, function ($a, $b) {
        $ac = !empty($a['entry']['claimed']) ? 0 : 1;
        $bc = !empty($b['entry']['claimed']) ? 0 : 1;
        if ($ac !== $bc) return $ac - $bc;
        return $a['index'] - $b['index'];
    });
    $primary = $list[0]['entry'];
    $primaryCanon = canonical((string)($primary['name'] ?? ''));
    if (isset($aliasMap[$primaryCanon])) {
        $primary['name'] = $aliasMap[$primaryCanon];
    }
    $evKey = static fn(array $e) => canonical_text((string)($e['title'] ?? ''))
                                  . '|' . canonical_day((string)($e['day'] ?? ''))
                                  . '|' . trim((string)($e['startTime'] ?? ''));
    $events = is_array($primary['events'] ?? null) ? $primary['events'] : [];
    $seen = [];
    $kept = [];
    foreach ($events as $ev) {
        $k = $evKey($ev);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $kept[] = $ev;
    }
    $claimed = !empty($primary['claimed']);
    for ($i = 1, $n = count($list); $i < $n; $i++) {
        if (!empty($list[$i]['entry']['claimed'])) $claimed = true;
        foreach (($list[$i]['entry']['events'] ?? []) as $ev) {
            $k = $evKey($ev);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $kept[] = $ev;
        }
    }
    $primary['events']  = $kept;
    $primary['claimed'] = $claimed;
    $dedupedEntries[]   = $primary;
}
$entries = $dedupedEntries;

// ── Compute the three buckets: missing / possible renames / cross-camp ────
// Phase 2 widens the matcher. Anything still flagged 'missing' means no
// fuzzy or cross-camp candidate exists either. The two new buckets surface
// likely renames so a human can confirm rather than auto-resolve (cheap to
// review, expensive to misjoin two genuinely-distinct events).
$missing       = [];
$possible      = [];   // same-camp same-day fuzzy / substring / description
$crossCamp     = [];   // exact title match under a different camp
$totalEvents   = 0;

// Build secondary indices off $sheetRows once (not per events.json event).
// Index A: (canonCamp|canonDay) → array of row records, for the same-camp,
// same-day candidate scan (substring / description / fuzzy).
// Index B: canonText → array of row records, for the cross-camp guest scan.
$rowsByCampDay = [];
$rowsByText    = [];
foreach ($sheetRows as $sr) {
    $kCD = $sr['canonCamp'] . '|' . $sr['canonDay'];
    $rowsByCampDay[$kCD][] = $sr;
    if ($sr['canonText'] !== '') {
        $rowsByText[$sr['canonText']][] = $sr;
    }
}

if ($sheetError === null && $eventsError === null) {
    foreach ($entries as $entry) {
        $camp = (string)($entry['name'] ?? '');
        $type = (string)($entry['type'] ?? '');
        $claimed = !empty($entry['claimed']);
        // Pre-resolve camp's canonical key (with alias) once per entry so
        // every event in the camp shares the lookup, matching fetch logic.
        $cCamp = canonical($camp);
        if (isset($aliasMap[$cCamp])) $cCamp = canonical($aliasMap[$cCamp]);
        foreach (($entry['events'] ?? []) as $e) {
            $totalEvents++;
            $title = (string)($e['title'] ?? '');
            $day   = (string)($e['day'] ?? '');
            $desc  = (string)($e['description'] ?? '');
            $cText = canonical_text($title);
            $cDay  = canonical_day($day);
            $cDesc = $desc !== '' ? canonical_text($desc) : '';

            // Step 0: exact (camp,title,day) hit — already in sheet, skip.
            $key = $cCamp . '|' . $cText . '|' . $cDay;
            if (isset($sheetKeys[$key])) continue;

            // Step 1: scan same-camp same-day sheet rows for a "close" match.
            // Substring catches prefix/host variants like "Balearic Brunch
            // Presents - Jetane: …" ↔ "Jetane: …" and "CRAIGISON (9000ears):
            // Poolside Dad Bod …" ↔ "Poolside Dad Bod …".
            // Description match catches full renames where the body of the
            // event stayed identical (e.g. "Electric Circus 3" → "Open Mic
            // 3:33 Performance Hour"). Fuzzy (Levenshtein ≤ 25%) catches
            // minor edits the canonicalizer can't fold (typos, word swaps).
            $candidates = $rowsByCampDay[$cCamp . '|' . $cDay] ?? [];
            $matchedRename = null;
            $matchReason   = '';
            foreach ($candidates as $cand) {
                if ($cand['canonText'] === $cText) {
                    // Same canonical title — already counted by the exact
                    // hit above; defensive skip (shouldn't actually fire).
                    continue;
                }
                $a = $cText;
                $b = $cand['canonText'];
                $la = strlen($a);
                $lb = strlen($b);
                // (a) Substring containment. 6-char floor on the shorter
                // side prevents trivial matches ("DJ" ⊂ "DJ Workshop").
                if ($la > 0 && $lb > 0) {
                    $shorter = min($la, $lb);
                    if ($shorter >= 6 && (strpos($a, $b) !== false || strpos($b, $a) !== false)) {
                        $matchedRename = $cand;
                        $matchReason   = 'substring';
                        break;
                    }
                }
                // (b) Description match (both sides non-empty so empty
                // descriptions don't collapse every event into the same key).
                if ($cDesc !== '' && $cand['canonDesc'] !== '' && $cDesc === $cand['canonDesc']) {
                    $matchedRename = $cand;
                    $matchReason   = 'description';
                    break;
                }
                // (c) Levenshtein within 25% of the longer side, gated by
                // an 8-char floor so short titles ("Yoga" vs "Toga") can't
                // collide. PHP's levenshtein is fine here: same-camp+day
                // candidate sets are tiny, and canon strings are short.
                $longer = max($la, $lb);
                if ($longer >= 8) {
                    $threshold = (int)ceil($longer * 0.25);
                    if (levenshtein($a, $b) <= $threshold) {
                        $matchedRename = $cand;
                        $matchReason   = 'fuzzy';
                        break;
                    }
                }
            }
            if ($matchedRename !== null) {
                $possible[] = [
                    'camp'       => $camp,
                    'type'       => $type,
                    'claimed'    => $claimed,
                    'title'      => $title,
                    'sheetTitle' => $matchedRename['rawTitle'],
                    'day'        => $day,
                    'startTime'  => (string)($e['startTime'] ?? ''),
                    'endTime'    => (string)($e['endTime'] ?? ''),
                    'reason'     => $matchReason,
                ];
                continue;
            }

            // Step 2: cross-camp guest detection. If this exact canonical
            // title appears in EXACTLY ONE other camp's row in the sheet,
            // treat as a guest event. "Exactly one" is critical: when two
            // camps both have a "Yoga" event we'd be guessing which camp
            // hosts it, so leave those as missing instead.
            if ($cText !== '' && isset($rowsByText[$cText])) {
                $otherCampHits = [];
                foreach ($rowsByText[$cText] as $cand) {
                    if ($cand['canonCamp'] !== $cCamp) {
                        $otherCampHits[] = $cand;
                    }
                }
                // Allow the same host camp to legitimately list a guest event across multiple days.
                if (count(array_unique(array_column($otherCampHits, 'canonCamp'))) === 1) {
                    $crossCamp[] = [
                        'camp'       => $camp,
                        'type'       => $type,
                        'claimed'    => $claimed,
                        'title'      => $title,
                        'day'        => $day,
                        'startTime'  => (string)($e['startTime'] ?? ''),
                        'endTime'    => (string)($e['endTime'] ?? ''),
                        'sheetCamp'  => $otherCampHits[0]['rawCamp'],
                        'sheetTitle' => $otherCampHits[0]['rawTitle'],
                    ];
                    continue;
                }
            }

            // Step 3: nothing matched — genuinely missing.
            $missing[] = [
                'camp'      => $camp,
                'type'      => $type,
                'claimed'   => $claimed,
                'title'     => $title,
                'day'       => $day,
                'startTime' => (string)($e['startTime'] ?? ''),
                'endTime'   => (string)($e['endTime'] ?? ''),
            ];
        }
    }
    // Sort all three buckets the same way: by camp (alpha), then by day
    // (Thu→Mon), then by start time, so the layout is predictable.
    $dayOrder = ['Thursday' => 0, 'Friday' => 1, 'Saturday' => 2, 'Sunday' => 3, 'Monday' => 4];
    $sortRows = function (array &$rows) use ($dayOrder): void {
        usort($rows, function ($a, $b) use ($dayOrder) {
            $c = strcasecmp($a['camp'], $b['camp']);
            if ($c !== 0) return $c;
            $da = $dayOrder[$a['day']] ?? 99;
            $db = $dayOrder[$b['day']] ?? 99;
            if ($da !== $db) return $da - $db;
            return strcmp($a['startTime'], $b['startTime']);
        });
    };
    $sortRows($missing);
    $sortRows($possible);
    $sortRows($crossCamp);
}

$sheetCount    = count($sheetKeys);
$missingCount  = count($missing);
$possibleCount = count($possible);
$crossCount    = count($crossCamp);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <title>Otherworld 2026 — Events not in spreadsheet</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght,SOFT@9..144,400..700,0..100&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --night-1: #0a1620;
      --night-2: #122332;
      --night-3: #1a3045;
      --moss-1: rgba(120, 180, 200, 0.10);
      --moss-2: rgba(120, 180, 200, 0.22);
      --cream: #f2ead0;
      --cream-soft: #d4cea7;
      --cream-dim: #95a3a9;
      --lime: #cce84e;
      --pink: #ff86bd;
      --cyan: #74dce8;
      --amber: #ffae5a;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Inter", -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
      background:
        radial-gradient(ellipse 80% 50% at 12% -10%, rgba(204, 232, 78, 0.10), transparent 60%),
        radial-gradient(ellipse 70% 60% at 92% 10%, rgba(255, 134, 189, 0.08), transparent 60%),
        var(--night-1);
      color: var(--cream);
      line-height: 1.5;
      min-height: 100vh;
    }
    header {
      padding: 22px 32px 14px;
      border-bottom: 1px solid var(--moss-1);
      display: flex;
      align-items: baseline;
      gap: 20px;
      flex-wrap: wrap;
    }
    h1 {
      font-family: "Fraunces", Georgia, serif;
      font-weight: 700;
      font-size: 26px;
      letter-spacing: -0.02em;
      margin: 0;
      color: var(--cream);
    }
    h1 .accent { color: var(--lime); font-style: italic; }
    header a {
      color: var(--cream-dim);
      text-decoration: none;
      font-size: 13px;
      font-weight: 500;
      border-bottom: 1px solid transparent;
      transition: color .15s ease, border-color .15s ease;
    }
    header a:hover { color: var(--cream); border-color: var(--moss-2); }
    .count {
      color: var(--cream-dim);
      font-size: 12px;
      margin-left: auto;
    }
    .count strong { color: var(--cream); font-weight: 600; }
    main {
      padding: 24px 32px 64px;
      max-width: 1100px;
      margin: 0 auto;
    }
    .blurb {
      color: var(--cream-dim);
      font-size: 13px;
      margin: 0 0 18px;
      line-height: 1.55;
    }
    .blurb a { color: var(--lime); text-decoration: none; border-bottom: 1px dashed rgba(204,232,78,0.4); }
    .blurb a:hover { color: var(--lime); border-bottom-style: solid; }
    .error {
      padding: 14px 18px;
      background: rgba(255, 134, 189, 0.10);
      border: 1px solid var(--pink);
      color: var(--cream);
      border-radius: 10px;
      margin-bottom: 16px;
      font-size: 14px;
    }
    .empty {
      padding: 40px 24px;
      text-align: center;
      color: var(--cream-dim);
      background: var(--night-2);
      border: 1px solid var(--moss-1);
      border-radius: 10px;
    }
    .table-wrap {
      overflow-x: auto;
      background: var(--night-2);
      border: 1px solid var(--moss-1);
      border-radius: 10px;
    }
    table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      font-size: 14px;
    }
    thead th {
      text-align: left;
      font-weight: 600;
      font-size: 11px;
      letter-spacing: 0.10em;
      text-transform: uppercase;
      color: var(--cream-dim);
      padding: 12px 16px;
      background: rgba(0, 0, 0, 0.18);
      border-bottom: 1px solid var(--moss-1);
      position: sticky;
      top: 0;
    }
    tbody td {
      padding: 12px 16px;
      border-bottom: 1px solid var(--moss-1);
      vertical-align: top;
    }
    tbody tr:last-child td { border-bottom: none; }
    tbody tr:hover td { background: var(--night-3); }
    .col-camp   { color: var(--cream); font-weight: 500; }
    .col-type   { color: var(--cream-dim); font-size: 12px; text-transform: capitalize; white-space: nowrap; width: 1%; }
    .col-title  { color: var(--cream-soft); }
    .col-day    { color: var(--cream-soft); white-space: nowrap; width: 1%; }
    .col-time   { color: var(--cream-dim); font-variant-numeric: tabular-nums; white-space: nowrap; width: 1%; font-size: 13px; }
    .col-flag   { white-space: nowrap; width: 1%; }
    .pill {
      display: inline-block;
      padding: 2px 9px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      border: 1px solid currentColor;
      white-space: nowrap;
    }
    .pill.claimed   { color: var(--cyan); }
    .pill.unclaimed { color: var(--amber); }
    /* Reason badges for the "Possible renames" bucket: smaller, less
       attention-grabbing than the claim pill, but still tonally consistent. */
    .badge {
      display: inline-block;
      padding: 1px 7px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 600;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      background: var(--moss-1);
      color: var(--cream-soft);
      border: 1px solid var(--moss-2);
      white-space: nowrap;
    }
    .badge.substring   { color: var(--lime); border-color: rgba(204,232,78,0.45); }
    .badge.description { color: var(--cyan); border-color: rgba(116,220,232,0.45); }
    .badge.fuzzy       { color: var(--pink); border-color: rgba(255,134,189,0.45); }
    /* Side-by-side title cell: events.json title above, sheet title below
       with a subtle arrow so the rename direction is obvious. */
    .rename-pair      { display: flex; flex-direction: column; gap: 4px; }
    .rename-pair .from { color: var(--cream-soft); }
    .rename-pair .to   { color: var(--cream-dim); font-size: 12px; }
    .rename-pair .to::before { content: '↳ '; color: var(--cream-dim); }
    /* <details> shells for collapsible sections. Keep visual rhythm close
       to the existing .table-wrap; the summary acts as the section header. */
    .section { margin-top: 22px; }
    .section:first-of-type { margin-top: 0; }
    details > summary {
      cursor: pointer;
      list-style: none;
      padding: 12px 16px;
      background: var(--night-2);
      border: 1px solid var(--moss-1);
      border-radius: 10px;
      display: flex;
      align-items: center;
      gap: 12px;
      font-family: "Fraunces", Georgia, serif;
      font-size: 17px;
      font-weight: 600;
      letter-spacing: -0.01em;
      color: var(--cream);
    }
    details[open] > summary {
      border-bottom-left-radius: 0;
      border-bottom-right-radius: 0;
      border-bottom: none;
    }
    details > summary::-webkit-details-marker { display: none; }
    details > summary::before {
      content: '▸';
      color: var(--cream-dim);
      transition: transform .15s ease;
      display: inline-block;
    }
    details[open] > summary::before { transform: rotate(90deg); }
    details > summary .count-inline {
      margin-left: auto;
      font-family: "Inter", sans-serif;
      font-size: 12px;
      font-weight: 500;
      color: var(--cream-dim);
    }
    details[open] > .table-wrap,
    details[open] > .empty {
      border-top-left-radius: 0;
      border-top-right-radius: 0;
    }
    details > .section-blurb {
      padding: 10px 16px 4px;
      margin: 0;
      color: var(--cream-dim);
      font-size: 12px;
      background: var(--night-2);
      border-left: 1px solid var(--moss-1);
      border-right: 1px solid var(--moss-1);
    }
    @media (max-width: 720px) {
      header { padding: 16px 16px 10px; }
      main   { padding: 16px; }
      h1     { font-size: 20px; }
      table  { font-size: 13px; }
      thead th, tbody td { padding: 10px 10px; }
    }
  </style>
</head>
<body>
  <header>
    <h1>Events <span class="accent">not in the spreadsheet</span></h1>
    <a href="./">← Back to schedule</a>
    <span class="count">
      <?php if ($sheetError === null && $eventsError === null): ?>
        <strong><?= $missingCount ?></strong> missing · <strong><?= $possibleCount ?></strong> possible renames · <strong><?= $crossCount ?></strong> cross-camp · <?= $totalEvents ?> total in events.json · <?= $sheetCount ?> in sheet
      <?php endif; ?>
    </span>
  </header>
  <main>
    <p class="blurb">
      Events in <code>events.json</code> are compared against the
      <a href="<?= h($SHEET_VIEW_URL) ?>" target="_blank" rel="noopener">shared spreadsheet</a>.
      Rows are grouped into three buckets: outright <strong>missing</strong>, likely <strong>renames</strong>
      (same camp + day, fuzzy title or matching description), and <strong>cross-camp guest events</strong>
      (this exact title lives under a different camp in the sheet).
    </p>

    <?php if ($eventsError !== null): ?>
      <div class="error"><strong>events.json:</strong> <?= h($eventsError) ?></div>
    <?php endif; ?>
    <?php if ($sheetError !== null): ?>
      <div class="error"><strong>Spreadsheet:</strong> <?= h($sheetError) ?></div>
    <?php endif; ?>

    <?php if ($sheetError === null && $eventsError === null): ?>

      <?php // Section 1: Missing from sheet — expanded by default since this
            // is what the page has always shown and the primary action item. ?>
      <details class="section" open>
        <summary>
          Missing from sheet
          <span class="count-inline"><?= $missingCount ?></span>
        </summary>
        <?php if ($missingCount === 0): ?>
          <div class="empty">Every event in events.json has a match in the spreadsheet. Nothing to mirror.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th class="col-camp">Camp</th>
                  <th class="col-type">Type</th>
                  <th class="col-title">Event</th>
                  <th class="col-day">Day</th>
                  <th class="col-time">Time</th>
                  <th class="col-flag">Claim</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($missing as $r): ?>
                  <tr>
                    <td class="col-camp"><?= h($r['camp']) ?></td>
                    <td class="col-type"><?= h(pretty_type($r['type'])) ?></td>
                    <td class="col-title"><?= h($r['title']) ?></td>
                    <td class="col-day"><?= h($r['day']) ?></td>
                    <td class="col-time">
                      <?= h($r['startTime']) ?><?= $r['endTime'] !== '' ? ' – ' . h($r['endTime']) : '' ?>
                    </td>
                    <td class="col-flag">
                      <?php if ($r['claimed']): ?>
                        <span class="pill claimed">✓ Claimed</span>
                      <?php else: ?>
                        <span class="pill unclaimed">unclaimed</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </details>

      <?php // Section 2: Possible renames — collapsed by default; needs a
            // human eyeball before any action. Show events.json title above
            // the sheet's title plus a small badge explaining why it matched. ?>
      <details class="section">
        <summary>
          Possible renames
          <span class="count-inline"><?= $possibleCount ?></span>
        </summary>
        <p class="section-blurb">Same camp + day in the sheet, but the title isn't an exact match. Review and either rename in events.json (or claim) or in the sheet to align.</p>
        <?php if ($possibleCount === 0): ?>
          <div class="empty">No fuzzy-title candidates found.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th class="col-camp">Camp</th>
                  <th class="col-type">Type</th>
                  <th class="col-title">events.json title → sheet title</th>
                  <th class="col-day">Day</th>
                  <th class="col-time">Time</th>
                  <th class="col-flag">Match</th>
                  <th class="col-flag">Claim</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($possible as $r): ?>
                  <tr>
                    <td class="col-camp"><?= h($r['camp']) ?></td>
                    <td class="col-type"><?= h(pretty_type($r['type'])) ?></td>
                    <td class="col-title">
                      <div class="rename-pair">
                        <span class="from"><?= h($r['title']) ?></span>
                        <span class="to"><?= h($r['sheetTitle']) ?></span>
                      </div>
                    </td>
                    <td class="col-day"><?= h($r['day']) ?></td>
                    <td class="col-time">
                      <?= h($r['startTime']) ?><?= $r['endTime'] !== '' ? ' – ' . h($r['endTime']) : '' ?>
                    </td>
                    <td class="col-flag">
                      <span class="badge <?= h($r['reason']) ?>"><?= h($r['reason']) ?></span>
                    </td>
                    <td class="col-flag">
                      <?php if ($r['claimed']): ?>
                        <span class="pill claimed">✓ Claimed</span>
                      <?php else: ?>
                        <span class="pill unclaimed">unclaimed</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </details>

      <?php // Section 3: Cross-camp guest events — collapsed by default.
            // These are titles that exist verbatim under a different camp's
            // sheet rows; usually a guest DJ / performer listed under their
            // home camp in the sheet but the host camp in events.json. ?>
      <details class="section">
        <summary>
          Cross-camp guest events
          <span class="count-inline"><?= $crossCount ?></span>
        </summary>
        <p class="section-blurb">Title matches exactly in the sheet — but under a different camp. Probably a guest event listed under its home camp in the sheet.</p>
        <?php if ($crossCount === 0): ?>
          <div class="empty">No cross-camp title matches found.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th class="col-camp">events.json camp → sheet camp</th>
                  <th class="col-type">Type</th>
                  <th class="col-title">Event</th>
                  <th class="col-day">Day</th>
                  <th class="col-time">Time</th>
                  <th class="col-flag">Claim</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($crossCamp as $r): ?>
                  <tr>
                    <td class="col-camp">
                      <div class="rename-pair">
                        <span class="from"><?= h($r['camp']) ?></span>
                        <span class="to"><?= h($r['sheetCamp']) ?></span>
                      </div>
                    </td>
                    <td class="col-type"><?= h(pretty_type($r['type'])) ?></td>
                    <td class="col-title"><?= h($r['title']) ?></td>
                    <td class="col-day"><?= h($r['day']) ?></td>
                    <td class="col-time">
                      <?= h($r['startTime']) ?><?= $r['endTime'] !== '' ? ' – ' . h($r['endTime']) : '' ?>
                    </td>
                    <td class="col-flag">
                      <?php if ($r['claimed']): ?>
                        <span class="pill claimed">✓ Claimed</span>
                      <?php else: ?>
                        <span class="pill unclaimed">unclaimed</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </details>

    <?php endif; ?>
  </main>
</body>
</html>
