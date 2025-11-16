<?php

use Castor\Attribute\AsTask;

use function Castor\{io,run,capture,import};

#[AsTask(description: 'Welcome to Castor!')]
function hello(): void
{
    $currentUser = capture('whoami');
    io()->title(sprintf('Hello %s!', $currentUser));
}

#[AsTask(description: 'dispatch detailed load')]
function dispatch(): void
{
    if (io()->confirm('Do you want to dispatch the load transition?')) {
        run('bin/console state:iterate Package --marking=new --transition=load');
        run('bin/console mess:stats');
    }
}

import(__DIR__ . '/src/Command/LoadDataCommand.php');
import(__DIR__ . '/src/Command/HelloCommand.php');
import('.castor/vendor/tacman/castor-tools/castor.php');
