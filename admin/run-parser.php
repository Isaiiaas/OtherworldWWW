<?php
/**
 * POST endpoint: run `node parse-map.js`.
 *
 * The parser preserves confirmed pins, so this is safe to call any
 * time — it'll only auto-suggest positions for entries that don't
 * already have a confirmed pin. Useful after:
 *   - The schedule (events.json) changed and new camps were added.
 *   - The map PDF (map.pdf) was replaced.
 *
 * Streams nothing — waits for the parser to finish (typically a few
 * seconds) and returns its stderr output as `log` so the UI can show
 * what happened.
 *
 * Returns 200 with { ok: true, log: "...", stats: {...} } on success.
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
$script  = $rootDir . '/parse-map.js';

if (!file_exists($script)) {
    http_response_code(500);
    echo json_encode(['error' => 'parse-map.js not found']);
    exit;
}

// Locate `node` — proc_open inherits the web server's PATH which often
// excludes Homebrew. Probe the usual spots, then fall back to PATH.
$nodeBin = locateNode();
if ($nodeBin === null) {
    http_response_code(500);
    echo json_encode(['error' => 'node not found on PATH']);
    exit;
}

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$proc = proc_open(
    [$nodeBin, $script],
    $descriptors,
    $pipes,
    $rootDir
);

if (!is_resource($proc)) {
    http_response_code(500);
    echo json_encode(['error' => 'could not spawn node']);
    exit;
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
$rc     = proc_close($proc);

if ($rc !== 0) {
    http_response_code(500);
    echo json_encode([
        'error' => "parse-map.js exited rc=$rc",
        'log'   => trim($stdout . "\n" . $stderr),
    ]);
    exit;
}

// Reload the freshly-written locations so the client can refresh stats.
$locationsPath = $rootDir . '/map-locations.json';
$stats = null;
if (file_exists($locationsPath)) {
    try {
        $loc = json_decode(file_get_contents($locationsPath), true, 16, JSON_THROW_ON_ERROR);
        $stats = [
            'total'     => count($loc['pins'] ?? []),
            'confirmed' => count(array_filter($loc['pins'] ?? [], static fn($p) => !empty($p['confirmed']))),
            'version'   => $loc['version'] ?? null,
        ];
    } catch (Throwable $e) { /* leave stats null */ }
}

echo json_encode([
    'ok'    => true,
    'log'   => trim($stdout . "\n" . $stderr),
    'stats' => $stats,
]);

function locateNode(): ?string {
    foreach (['/opt/homebrew/bin/node', '/usr/local/bin/node', '/usr/bin/node'] as $candidate) {
        if (is_executable($candidate)) return $candidate;
    }
    $which = trim((string) @shell_exec('command -v node 2>/dev/null'));
    return $which !== '' && is_executable($which) ? $which : null;
}
