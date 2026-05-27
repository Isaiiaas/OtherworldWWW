<?php
/**
 * POST endpoint: persist neighborhood polygon edits from
 * neighborhood-annotate.php.
 *
 * Body (JSON):
 *   {
 *     neighbourhoods: [
 *       { name, points: [{x,y}, ...], confirmed? },
 *       ...
 *     ]
 *   }
 *
 * Writes the canonical `map-neighbourhoods.json` at the repo root.
 * Page dimensions are copied from map-locations.json (kept in sync so
 * the two files agree on what map.png's coordinate space is). Each
 * point's x/y is normalized to [0, 1] of the map image.
 *
 * No coordinate-rounding magic, no smoothing — what the annotator UI
 * sent is what we save (minus invalid / out-of-bounds points).
 */
declare(strict_types=1);

require __DIR__ . '/require-local.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$rootDir   = dirname(__DIR__);
$outPath   = $rootDir . '/map-neighbourhoods.json';
$locPath   = $rootDir . '/map-locations.json';

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}
try {
    $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . $e->getMessage()]);
    exit;
}
if (!is_array($body) || !isset($body['neighbourhoods']) || !is_array($body['neighbourhoods'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing neighbourhoods array']);
    exit;
}

$out = [];
foreach ($body['neighbourhoods'] as $n) {
    if (!is_array($n)) continue;
    $name = isset($n['name']) ? trim((string)$n['name']) : '';
    if ($name === '') continue;

    $points = [];
    if (isset($n['points']) && is_array($n['points'])) {
        foreach ($n['points'] as $p) {
            if (!is_array($p) || !isset($p['x'], $p['y'])) continue;
            $x = (float)$p['x'];
            $y = (float)$p['y'];
            if (!is_finite($x) || !is_finite($y)) continue;
            // Clamp rather than drop: a corner dragged a touch past the
            // edge by the user is still meaningful intent.
            $x = max(0.0, min(1.0, $x));
            $y = max(0.0, min(1.0, $y));
            $points[] = ['x' => round($x, 4), 'y' => round($y, 4)];
        }
    }

    $entry = ['name' => $name];
    if ($points) $entry['points'] = $points;
    if (!empty($n['confirmed'])) $entry['confirmed'] = true;
    $out[] = $entry;
}

// Pull page dims from map-locations.json so the two files agree.
$pageW = 794.578125;
$pageH = 615.341553;
if (file_exists($locPath)) {
    $loc = json_decode((string)file_get_contents($locPath), true);
    if (is_array($loc)) {
        if (isset($loc['pageWidth']))  $pageW = (float)$loc['pageWidth'];
        if (isset($loc['pageHeight'])) $pageH = (float)$loc['pageHeight'];
    }
}

usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$data = [
    'pageWidth'      => $pageW,
    'pageHeight'     => $pageH,
    'updatedAt'      => gmdate('c'),
    'neighbourhoods' => $out,
];

$json = json_encode(
    $data,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
if ($json === false) {
    http_response_code(500);
    echo json_encode(['error' => 'json_encode failed']);
    exit;
}

$tmp = $outPath . '.tmp';
if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Write failed']);
    exit;
}
if (!rename($tmp, $outPath)) {
    @unlink($tmp);
    http_response_code(500);
    echo json_encode(['error' => 'Rename failed']);
    exit;
}

echo json_encode(['ok' => true, 'count' => count($out)]);
