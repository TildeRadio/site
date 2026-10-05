<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/dj', __DIR__ . '/lib/DjAuth', __DIR__ . '/lib/Admin', __DIR__ . '/tests/DjAuth', __DIR__ . '/tests/Admin', __DIR__ . '/tests/fixtures'])
    ->append([__DIR__ . '/bin/lint-dj-auth.php', __DIR__ . '/bin/backup-dj-admin.php', __FILE__]);

return (new PhpCsFixer\Config())
    ->setRules(['@PSR12' => true, 'declare_strict_types' => true])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
