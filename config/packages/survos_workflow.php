<?php

declare(strict_types=1);

use Symfony\Config\FrameworkConfig;
use Survos\WorkflowBundle\Service\ConfigureFromAttributesService;
use App\Workflow\MusdigObjectWorkflow;
use App\Workflow\ImageWorkflow;
use \App\Workflow\MetWorkflow;

return static function (FrameworkConfig $framework) {

    foreach ([
        // doctrine
                 \App\Workflow\BundleWorkflow::class,
             ] as $workflowClass) {
        ConfigureFromAttributesService::configureFramework($workflowClass, $framework, [$workflowClass]);
    }

};
