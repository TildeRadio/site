<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/radio.php';
require_once dirname(__DIR__, 2) . '/lib/api.php';

$now = tr_now_playing();

tr_api_json($now, !empty($now['available']) ? 200 : 503, 10);
