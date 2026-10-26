<?php

use App\Tests\Support\RemoteFixtures;
use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    // Deployment-independent defaults; machine-specific values belong in env vars.
    'parameters' => ['router.request_context.scheme' => 'https', 'asset.request_context.secure' => true],
    'services' => [
        // Autowire dependencies and autoconfigure commands, subscribers, and handlers.
        '_defaults' => ['autowire' => true, 'autoconfigure' => true],
        // Discover src/ classes as services using their fully qualified names.
        'App\\' => ['resource' => '../src/'],
    ],
    // tests/ is outside the application service discovery resource.
    'when@test' => [
        'services' => [
            '_defaults' => ['autowire' => false, 'autoconfigure' => false],
            RemoteFixtures::class => null,
        ],
    ],
]);
