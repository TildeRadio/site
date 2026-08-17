<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/radio.php';
require_once dirname(__DIR__, 2) . '/lib/api.php';

$now = tr_now_playing();

tr_api_json([
    'history' => is_array($now['history'] ?? null) ? $now['history'] : [],
    'updated_at' => is_int($now['updated_at'] ?? null) ? $now['updated_at'] : time(),
], !empty($now['available']) ? 200 : 503, 10);
