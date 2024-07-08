<?php

declare(strict_types=1);

use Survos\WorkflowBundle\Service\ConfigureFromAttributesService;
use Symfony\Config\FrameworkConfig;

return static function (FrameworkConfig $framework) {
//return static function (ContainerConfigurator $containerConfigurator): void {

    if (class_exists(ConfigureFromAttributesService::class))
//        $files = glob(__DIR__."/../../src/Workflow/*Workflow.php");
//        dd($files);
        $workflowClasses = [
            \App\Workflow\BundleWorkflow::class
        ];
    foreach ($workflowClasses as $workflowClass) {
        ConfigureFromAttributesService::configureFramework($workflowClass, $framework, [$workflowClass]);
    }
//    foreach ($files as $workflowFilename) {
//        $workflowClass = new ReflectionClass($workflowFilename);
//        ConfigureFromAttributesService::configureFramework($workflowClass, $framework, [$workflowClass]);
//    }

};
