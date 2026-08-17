<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/radio.php';

$now = tr_now_playing();
$next = tr_next_schedule_event();

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=10, stale-while-revalidate=20');

$track = $now['now_playing']['text'] ?? null;
$dj = $now['dj'] ?? null;
$listeners = $now['listeners'] ?? null;

$lines = [
    '~ tilderadio ~',
    '',
    'now:       ' . ($track ?: 'unknown'),
    'dj:        ' . ($dj ?: 'AutoDJ'),
    'listeners: ' . (is_int($listeners) ? (string) $listeners : 'n/a'),
];

if ($next !== null) {
    $label = !empty($next['is_live']) ? 'scheduled: ' : 'next:      ';
    $lines[] = $label . $next['name'] . ' @ ' . gmdate('D M d H:i', $next['start_ts']) . ' UTC';
}

if (!empty($now['history'])) {
    $lines[] = '';
    $lines[] = 'recent:';
    foreach (array_slice($now['history'], 0, 5) as $item) {
        $lines[] = '  - ' . ($item['text'] ?? 'unknown');
    }
}

$lines[] = '';
$lines[] = 'listen: https://tilderadio.org/listen';
$lines[] = 'api:    https://tilderadio.org/api/now/';

echo implode("\n", $lines) . "\n";
