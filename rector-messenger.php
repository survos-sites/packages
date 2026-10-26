<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/config/packages/messenger.php'])
    ->withRules([StringClassNameToClassConstantRector::class])
    ->withImportNames()
    ->withCache(sys_get_temp_dir() . '/packages-rector-messenger');
