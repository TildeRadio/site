<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/radio.php';
require_once dirname(__DIR__, 2) . '/lib/api.php';

tr_api_json([
    'djs' => array_values(tr_dj_catalog()),
    'updated_at' => time(),
], 200, 60);
