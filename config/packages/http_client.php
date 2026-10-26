<?php

use App\Tests\Support\RemoteFixtures;
use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'framework' => [
        'http_client' => [
            'scoped_clients' => [
                // Package responses allow 12h shared caching; /p2/ metadata uses
                // max-age=900 and ETag/Last-Modified revalidation. Keep retries and
                // repeated loads cheap without adding cache decisions to workflows.
                'packagist.client' => [
                    'base_uri' => 'https://packagist.org',
                    'caching' => ['enabled' => true, 'shared' => true, 'max_ttl' => 43200],
                ],
            ],
        ],
    ],
    // Recorded Packagist/GitHub responses; unexpected requests fail the test.
    'when@test' => [
        'framework' => [
            'http_client' => ['mock_response_factory' => RemoteFixtures::class],
        ],
    ],
]);
