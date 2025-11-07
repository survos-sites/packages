<?php

use Castor\Attribute\AsTask;

use function Castor\io;
use function Castor\capture;
use function Castor\import;

#[AsTask(description: 'Welcome to Castor!')]
function hello(): void
{
    $currentUser = capture('whoami');
    io()->title(sprintf('Hello %s!', $currentUser));
}

#[AsTask(description: 'Load the data!')]
function load(): void
{
    \Castor\run("bin/console app:load");
}

import(__DIR__ . '/src/Command/LoadDataCommand.php');
import(__DIR__ . '/src/Command/HelloCommand.php');
import('.castor/vendor/tacman/castor-tools/castor.php');
