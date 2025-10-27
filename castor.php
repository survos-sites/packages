<?php

use Castor\Attribute\AsTask;
use Castor\Attribute\AsSymfonyTask;

use function Castor\{import, io, fs, capture, run};

import('.castor/vendor/tacman/castor-tools/castor.php');

//#[AsTask('load', "Load using symfony")]
//#[AsSymfonyTask('app:load-data')]
//function load(): void
//{
//    run('php bin/console app:load-data');
//}


