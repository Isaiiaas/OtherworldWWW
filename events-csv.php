<?php
declare(strict_types=1);

$EVENTS_FILE = __DIR__ . '/events.json';

if (!is_readable($EVENTS_FILE)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "events.json not found\n";
    exit;
}

$raw = file_get_contents($EVENTS_FILE);
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "events.json is malformed\n";
    exit;
}

$filename = 'otherworld-events-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel opens it correctly.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'owner',
    'owner_type',
    'day',
    'start_time',
    'end_time',
    'duration_hours',
    'crosses_midnight',
    'title',
    'description',
    'raw_time_text',
    'normalization_flags',
], ',', '"', '\\');

foreach ($data['entries'] as $entry) {
    $events = $entry['events'] ?? [];
    if (!is_array($events)) {
        continue;
    }
    foreach ($events as $ev) {
        $flags = $ev['normalizationFlags'] ?? [];
        if (is_array($flags)) {
            $flags = implode('|', $flags);
        }
        fputcsv($out, [
            $ev['owner']         ?? ($entry['name'] ?? ''),
            $ev['ownerType']     ?? ($entry['type'] ?? ''),
            $ev['day']           ?? '',
            $ev['startTime']     ?? '',
            $ev['endTime']       ?? '',
            $ev['durationHours'] ?? '',
            isset($ev['crossesMidnight']) ? ($ev['crossesMidnight'] ? 'true' : 'false') : '',
            $ev['title']         ?? '',
            $ev['description']   ?? '',
            $ev['rawTimeText']   ?? '',
            $flags,
        ], ',', '"', '\\');
    }
}

fclose($out);
