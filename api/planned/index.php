<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/api.php';
require_once dirname(__DIR__, 2) . '/lib/Admin/PublicProfiles.php';
require_once dirname(__DIR__, 2) . '/lib/Admin/PublicPlans.php';
tr_api_json(['preparations' => \TildeRadio\Site\Admin\PublicPlans::upcoming()], 200, 30);
