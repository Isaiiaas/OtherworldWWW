<?php
/**
 * POST endpoint: remove the parsed map.
 *
 * Deletes the rendered image (map.png) plus everything derived from it:
 *   - map-locations.json  (pin positions)
 *   - map-labels.json     (text clusters)
 *   - map-data.js         (runtime data for index.html)
 *
 * The source PDF (map.pdf) is NOT touched — re-running the parser will
 * rebuild everything from it. events.json / data.js (schedule) are also
 * left alone.
 *
 * Returns 200 with { ok: true, deleted: [...] } on success.
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

$rootDir = dirname(__DIR__);
$targets = [
    'map.png',
    'map-data.js',
    'map-locations.json',
    'map-labels.json',
];

$deleted = [];
$failed  = [];
foreach ($targets as $name) {
    $path = $rootDir . '/' . $name;
    if (!file_exists($path)) continue;
    if (@unlink($path)) {
        $deleted[] = $name;
    } else {
        $failed[] = $name;
    }
}

if (!empty($failed)) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'could not delete: ' . implode(', ', $failed),
        'deleted' => $deleted,
    ]);
    exit;
}

echo json_encode(['ok' => true, 'deleted' => $deleted]);
