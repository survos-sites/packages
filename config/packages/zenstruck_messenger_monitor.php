<?php

use App\Entity\ProcessedMessage;
use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'zenstruck_messenger_monitor' => [
        'storage' => [
            'orm' => ['entity_class' => ProcessedMessage::class],
            // Message classes (including interfaces) to exclude from monitoring.
            'exclude' => null,
        ],
    ],
]);
