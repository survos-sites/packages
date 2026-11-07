<?php

use Survos\ElasticBundle\Message\ReindexDocuments;
use Survos\ElasticBundle\Message\RemoveDocuments;
use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'framework' => [
        'messenger' => [
            'failure_transport' => 'failed',
            'transports' => [
                'bundle.load' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN_RABBITMQ)%',
                    'options' => [
                        'exchange' => ['name' => 'bundle.load'],
                        'queues' => ['bundle.load' => null],
                        // The jwage PR adjusts this to the worker's requested fetch size.
                        'prefetch_count' => 1,
                        'delay' => ['durable' => true],
                    ],
                    'retry_strategy' => ['max_retries' => 3, 'multiplier' => 2],
                ],
                'bundle.fetch.docs' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN_RABBITMQ)%',
                    'options' => [
                        'prefetch_count' => 1,
                        'exchange' => ['name' => 'bundle.fetch.docs'],
                        'queues' => ['bundle.fetch.docs' => null],
                        'delay' => ['durable' => true],
                    ],
                    'retry_strategy' => ['max_retries' => 3, 'multiplier' => 2],
                ],
                'elastic' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN_RABBITMQ)%',
                    'options' => [
                        'prefetch_count' => 50,
                        'exchange' => ['name' => 'packages.elastic'],
                        'queues' => ['packages.elastic' => null],
                        'delay' => ['durable' => true],
                    ],
                    'retry_strategy' => ['max_retries' => 3, 'multiplier' => 2],
                ],
                'failed' => 'doctrine://default?queue_name=failed&auto_setup=true',
                'sync' => 'sync://',
            ],
            'default_bus' => 'messenger.bus.default',
            'buses' => [
                'messenger.bus.default' => [
                    'middleware' => ['logging'],
                ],
            ],
            'routing' => [
                ReindexDocuments::class => 'elastic',
                RemoveDocuments::class => 'elastic',
            ],
        ],
    ],
]);
