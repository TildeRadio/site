<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/radio.php';
require_once dirname(__DIR__, 2) . '/lib/api.php';

tr_api_json([
    'schedule' => tr_schedule(),
    'updated_at' => time(),
], 200, 60);
