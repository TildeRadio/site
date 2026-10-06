<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/dj', __DIR__ . '/lib/DjAuth', __DIR__ . '/lib/Admin', __DIR__ . '/tests/DjAuth', __DIR__ . '/tests/Admin', __DIR__ . '/tests/fixtures', __DIR__ . '/help', __DIR__ . '/lib/Help', __DIR__ . '/tests/Help'])
    ->append([__DIR__ . '/bin/lint-dj-auth.php', __DIR__ . '/bin/backup-dj-admin.php', __DIR__ . '/bin/carrier-sync.php', __DIR__ . '/bin/carrier-recordings.php', __DIR__ . '/recordings/index.php', __DIR__ . '/bin/import-recordings.php', __DIR__ . '/community/live.php', __DIR__ . '/community/upcoming.php', __DIR__ . '/api/planned/index.php', __DIR__ . '/api/podcast/index.php', __DIR__ . '/djinfo/index.php', __DIR__ . '/community/carrier/index.php', __FILE__]);

return (new PhpCsFixer\Config())
    ->setRules(['@PSR12' => true, 'declare_strict_types' => true])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
