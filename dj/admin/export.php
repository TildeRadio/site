<?php

declare(strict_types=1);

require __DIR__ . '/_admin.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    tr_dj_error(405, 'This page accepts GET requests only.');
}
header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="tilderadio-website-records-' . gmdate('Y-m-d') . '.json"');
echo json_encode([
    'format' => 1, 'generated_at' => time(),
    'stations' => array_merge($djStore->stations(), $djStore->stations(true)),
    'accounts' => array_merge($djStore->accounts(), $djStore->accounts(true)),
    'profiles' => array_merge($djStore->profiles(), $djStore->profiles(true)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
