<?php
/**
 * POST endpoint: clear all pins.
 *
 * Wipes the pin list from map-locations.json and regenerates map-data.js
 * with an empty pin list. Page dimensions and version are preserved if
 * known; otherwise zeroed. Map image and labels file are left intact.
 *
 * Returns 200 with { ok: true } on success.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$rootDir       = dirname(__DIR__);
$locationsPath = $rootDir . '/map-locations.json';
$mapDataJs     = $rootDir . '/map-data.js';
$mapPng        = $rootDir . '/map.png';

try {
    $existing = file_exists($locationsPath)
        ? json_decode(file_get_contents($locationsPath), true, 16, JSON_THROW_ON_ERROR)
        : [];

    $version = file_exists($mapPng)
        ? substr((string) md5_file($mapPng), 0, 12)
        : ($existing['version'] ?? '0');

    $output = [
        'pageWidth'  => $existing['pageWidth']  ?? 1,
        'pageHeight' => $existing['pageHeight'] ?? 1,
        'version'    => $version,
        'imagePath'  => 'map.png',
        'pins'       => [],
    ];

    writeAtomic(
        $locationsPath,
        json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    writeAtomic(
        $mapDataJs,
        'window.OTHERWORLD_MAP = '
            . json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ";\n"
    );

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function writeAtomic(string $path, string $contents): void {
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $contents) === false) {
        throw new RuntimeException("write failed: $tmp");
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("rename failed");
    }
}
