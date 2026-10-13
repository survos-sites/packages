<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use App\Entity\Package;
use App\Workflow\BundleWorkflowInterface as WF;
use Survos\StateBundle\Doctrine\InitialPlaceKickoffListener;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\StateBundle\Service\WorkflowListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Temporary bridge for the legacy explorer/CLI, never a second workflow registrar. */
final class NativeWorkflowCompatibilityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->removeDefinition(InitialPlaceKickoffListener::class);
        $container->removeDefinition(WorkflowListener::class);
        $configuration = [WF::WORKFLOW_NAME => ['supports' => [Package::class], 'type' => 'state_machine', 'marking_store' => ['property' => 'marking']]];
        $container->setParameter('workflows.configuration', $configuration);
        $container->findDefinition(WorkflowHelperService::class)
            ->clearTag('messenger.message_handler')
            ->setArgument('$configuration', $configuration)
            ->setArgument('$definitionClasses', ['by_workflow' => [WF::WORKFLOW_NAME => WF::class], 'by_support' => [Package::class => [WF::class]]]);
        // Existing state:iterate/debug controls still send legacy messages; the app
        // bridge consumes those through the new pipeline while old queues drain.
        $container->findDefinition(AsyncQueueLocator::class)
            ->setArgument('$map', ['bundle' => [WF::TRANSITION_LOAD => 'bundle.load', WF::TRANSITION_FETCH_DOCS => 'bundle.fetch.docs']]);
    }
}
