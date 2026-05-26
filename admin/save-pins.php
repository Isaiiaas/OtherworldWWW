<?php
/**
 * POST endpoint: persist pin edits from map-annotate.php.
 *
 * Body (JSON):
 *   {
 *     pins: [ { name, type, x, y, confirmed, confidence?, matchedText? }, ... ]
 *   }
 *
 * Writes the canonical `map-locations.json` and regenerates `map-data.js`
 * (used by index.html at runtime). The PNG file is left untouched —
 * re-render via `node parse-map.js` if the PDF changes.
 *
 * Pins are reconciled with the schedule's entry list (events.json): any
 * pin whose name no longer corresponds to a known camp/stage/art is
 * dropped silently. Pin type is forced to match the schedule type.
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
$eventsPath    = $rootDir . '/events.json';
$mapPng        = $rootDir . '/map.png';

try {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        throw new RuntimeException('empty body');
    }
    $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($body) || !isset($body['pins']) || !is_array($body['pins'])) {
        throw new RuntimeException('expected { pins: [...] }');
    }

    // Index of valid entry name → type (camp/sound_stage/art_installation).
    $entries = json_decode(file_get_contents($eventsPath), true, 32, JSON_THROW_ON_ERROR);
    $wanted  = ['camp' => true, 'sound_stage' => true, 'art_installation' => true];
    $typeByName = [];
    foreach ($entries['entries'] as $e) {
        if (!isset($wanted[$e['type']])) continue;
        $typeByName[$e['name']] = $e['type'];
    }

    // Existing file gives us page dimensions (set once by parse-map.js)
    // and a default for any pin that arrives without explicit confidence.
    $existing = file_exists($locationsPath)
        ? json_decode(file_get_contents($locationsPath), true, 16, JSON_THROW_ON_ERROR)
        : ['pageWidth' => 1, 'pageHeight' => 1, 'pins' => []];

    $cleanPins = [];
    foreach ($body['pins'] as $p) {
        if (!is_array($p) || !isset($p['name'], $p['x'], $p['y'])) continue;
        $name = (string) $p['name'];
        if (!isset($typeByName[$name])) continue; // unknown entry — drop
        $x = (float) $p['x'];
        $y = (float) $p['y'];
        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) continue;

        $pin = [
            'name'      => $name,
            'type'      => $typeByName[$name],
            'x'         => round($x, 4),
            'y'         => round($y, 4),
            'confirmed' => !empty($p['confirmed']),
        ];
        if (isset($p['confidence']) && is_numeric($p['confidence'])) {
            $pin['confidence'] = round((float) $p['confidence'], 2);
        }
        if (isset($p['matchedText']) && is_string($p['matchedText'])) {
            $pin['matchedText'] = $p['matchedText'];
        }
        // Preserve provenance + QA fields so re-running the parser
        // doesn't lose them, and the UI can keep showing badges.
        foreach (['source', 'qaVerdict', 'qaEvidence'] as $strField) {
            if (isset($p[$strField]) && is_string($p[$strField])) {
                $pin[$strField] = $p[$strField];
            }
        }
        if (isset($p['qaConfidence']) && is_numeric($p['qaConfidence'])) {
            $pin['qaConfidence'] = round((float) $p['qaConfidence'], 2);
        }
        if (!empty($p['verified'])) $pin['verified'] = true;
        if (!empty($p['qaWrong']))  $pin['qaWrong']  = true;
        $cleanPins[] = $pin;
    }

    // Stable order: type then name (case-insensitive).
    $typeOrder = ['camp' => 0, 'sound_stage' => 1, 'art_installation' => 2];
    usort($cleanPins, static function ($a, $b) use ($typeOrder) {
        $ia = $typeOrder[$a['type']] ?? 9;
        $ib = $typeOrder[$b['type']] ?? 9;
        if ($ia !== $ib) return $ia - $ib;
        return strcasecmp($a['name'], $b['name']);
    });

    $version = file_exists($mapPng)
        ? substr(md5_file($mapPng), 0, 12)
        : ($existing['version'] ?? '0');

    $output = [
        'pageWidth'  => $existing['pageWidth']  ?? 1,
        'pageHeight' => $existing['pageHeight'] ?? 1,
        'version'    => $version,
        'imagePath'  => 'map.png',
        'pins'       => $cleanPins,
    ];

    // Atomic write to avoid serving a half-written file if two saves race.
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

    $counts = ['camp' => 0, 'sound_stage' => 0, 'art_installation' => 0, 'confirmed' => 0];
    foreach ($cleanPins as $p) {
        $counts[$p['type']]++;
        if (!empty($p['confirmed'])) $counts['confirmed']++;
    }

    echo json_encode([
        'ok'      => true,
        'version' => $version,
        'counts'  => $counts,
        'total'   => count($cleanPins),
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

function writeAtomic(string $path, string $contents): void {
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $contents) === false) {
        throw new RuntimeException("write failed: $tmp");
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("rename failed: $tmp -> $path");
    }
}
