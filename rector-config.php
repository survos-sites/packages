<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/config/services.php',
        __DIR__ . '/config/packages/knp_packagist_api.php',
        __DIR__ . '/config/packages/http_discovery.php',
        __DIR__ . '/config/packages/security.php',
        __DIR__ . '/config/packages/http_client.php',
        __DIR__ . '/config/packages/sentry.php',
        __DIR__ . '/config/packages/zenstruck_messenger_monitor.php',
        __DIR__ . '/config/packages/messenger.php',
    ])
    ->withRules([StringClassNameToClassConstantRector::class])
    ->withImportNames()
    ->withCache(sys_get_temp_dir() . '/packages-rector-config');
