<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/radio.php';
require_once dirname(__DIR__, 2) . '/lib/api.php';

$archive = tr_episode_archive();

tr_api_json([
    'episodes' => $archive['episodes'],
    'generated_at' => $archive['generated_at'],
], 200, 30);
