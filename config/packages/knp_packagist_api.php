<?php

use Symfony\Component\HttpClient\Psr18Client;
use Packagist\Api\Client;
use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'services' => [
        // Preserve the explicit wiring used by the original YAML definitions.
        '_defaults' => ['autowire' => false, 'autoconfigure' => false],
        'packagist.psr18_client' => [
            'class' => Psr18Client::class,
            'arguments' => ['@packagist.client'],
        ],
        Client::class => [
            'arguments' => ['$httpClient' => '@packagist.psr18_client'],
        ],
    ],
]);
