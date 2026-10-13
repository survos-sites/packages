<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Package;
use Doctrine\ORM\EntityManagerInterface;
use Survos\StateBundle\Message\TransitionMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;

/** Drain old queue payloads and support legacy explorer/iterate controls during migration. */
#[AsMessageHandler]
final readonly class LegacyPackageTransitionHandler
{
    public function __construct(private EntityManagerInterface $em, private PackagePipeline $pipeline, private \Survos\StateBundle\Service\AsyncQueueLocator $legacyRouting) {}

    public function __invoke(TransitionMessage $message): array
    {
        if ($message->getClassName() !== Package::class || $message->getWorkflow() !== BundleWorkflowInterface::WORKFLOW_NAME) {
            throw new UnrecoverableMessageHandlingException('Unsupported legacy workflow message.');
        }
        $package = $this->em->find(Package::class, $message->getId());
        if ($package === null) {
            return ['status' => 'missing'];
        }
        $previousSync = $this->pipeline->sync;
        $this->pipeline->sync = $previousSync || $this->legacyRouting->sync;
        try {
            $this->pipeline->apply($package, $message->getTransitionName(), $message->getContext());
        } catch (NotEnabledTransitionException) {
            return ['status' => 'blocked'];
        } finally {
            $this->pipeline->sync = $previousSync;
        }
        return ['status' => 'applied'];
    }
}
