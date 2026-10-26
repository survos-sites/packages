<?php

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'security' => [
        'password_hashers' => [PasswordAuthenticatedUserInterface::class => 'auto'],
        'providers' => [
            'users_in_memory' => ['memory' => null],
        ],
        'firewalls' => [
            // Keep development tools and static assets outside authentication.
            'dev' => ['pattern' => '^/(_profiler|_wdt|assets|build)/', 'security' => false],
            'main' => ['lazy' => true, 'provider' => 'users_in_memory'],
        ],
        // When adding rules, only the first matching access-control rule applies.
        'access_control' => null,
    ],
    // Reduce password-hashing cost only in tests (bcrypt: 4; argon: 3/10).
    'when@test' => [
        'security' => [
            'password_hashers' => [
                PasswordAuthenticatedUserInterface::class => ['algorithm' => 'auto', 'cost' => 4, 'time_cost' => 3, 'memory_cost' => 10],
            ],
        ],
    ],
]);
