<?php

use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'when@prod' => [
        'sentry' => [
            'dsn' => '%env(SENTRY_DSN)%',
            // PII collection remains disabled; see Sentry's data-collected docs.
            'options' => [
                'ignore_exceptions' => [FatalError::class, 'Symfony\\Component\\Debug\\Exception\\FatalErrorException'],
            ],
        ],
    ],
]);
