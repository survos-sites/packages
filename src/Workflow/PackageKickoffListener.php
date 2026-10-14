<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Package;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postFlush)]
final class PackageKickoffListener implements ResetInterface
{
    /** @var array<string, array{Package, array, bool}> */
    private array $pending = [];
    private bool $dispatching = false;

    public function __construct(private readonly PackagePipeline $pipeline) {}

    public function postPersist(PostPersistEventArgs $event): void
    {
        if (($package = $event->getObject()) instanceof Package) {
            $this->pending[$package->id] ??= [$package, [], true];
        }
    }

    #[AsCompletedListener(PackageWorkflow::WORKFLOW_NAME)]
    public function completed(CompletedEvent $event): void
    {
        $package = $event->getSubject();
        if ($package instanceof Package) {
            $this->pending[$package->id] = [$package, $event->getContext(), false];
        }
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        if ($this->dispatching || $this->pending === []) {
            return;
        }
        if ($event->getObjectManager()->getConnection()->isTransactionActive()) {
            throw new \LogicException('New package kickoff requires a committed flush; transactional producers need an outbox.');
        }
        $this->dispatching = true;
        try {
            while ($this->pending !== []) {
                $pending = $this->pending;
                $this->pending = [];
                foreach ($pending as [$package, $context, $initial]) {
                    if ($initial) {
                        $this->pipeline->kickoff($package);
                    } else {
                        $this->pipeline->continueAfterFlush($package, $context);
                    }
                }
            }
        } finally {
            $this->dispatching = false;
        }
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->dispatching = false;
    }
}
