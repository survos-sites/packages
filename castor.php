<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;

use function Castor\{io,run,capture,import};

#[AsTask(description: 'Welcome to Castor!')]
function hello(): void
{
    $currentUser = capture('whoami');
    io()->title(sprintf('Hello %s!', $currentUser));
}

#[AsTask(description: 'Discover bundles and automatically start newly inserted packages')]
function dispatch(): void
{
    if (io()->confirm('Discover new bundles and start their workflows?')) {
        run('bin/console app:load-data');
        run('bin/console mess:stats');
    }
}

//import(__DIR__ . '/src/Command/LoadDataCommand.php');
//import(__DIR__ . '/src/Command/HelloCommand.php');
