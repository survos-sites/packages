<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Package;
use Doctrine\ORM\EntityManagerInterface;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Survos\WorkflowMessengerBundle\Service\TransitionDispatcher;
use Survos\WorkflowExtrasBundle\Service\NextTransitionRunner;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/** Package persistence adapter; selection and execution live in the workflow bundles. */
final class PackagePipeline implements \Survos\WorkflowExtrasBundle\Contract\WorkflowContinuationInterface
{
    public bool $sync = false;

    public function __construct(
        #[Target(PackageWorkflow::WORKFLOW_NAME)] private readonly WorkflowInterface $workflow,
        private readonly NextTransitionRunner $next,
        private readonly TransitionDispatcher $dispatcher,
        private readonly EntityManagerInterface $em,
    ) {}

    public function dispatch(Package $package, string $transition, array $context = []): void
    {
        $this->requireCommittedBoundary();
        if ($this->sync) $context['cascade'] = 'sync';
        $this->dispatcher->dispatch(PackageWorkflow::WORKFLOW_NAME, self::reference($package), $transition, $context);
    }

    public function apply(Package $package, string $transition, array $context = []): void
    {
        $this->requireCommittedBoundary();
        $this->workflow->apply($package, $transition, $context);
        $this->finish($package, $context);
    }

    /** Persist a direct native apply after its listeners finish. */
    public function finish(Package $package, array $context = []): void
    {
        $this->requireCommittedBoundary();
        $this->em->flush();
    }

    public function supports(object $subject, ?string $workflowName = null): bool
    {
        return $subject instanceof Package && ($workflowName === null || $workflowName === PackageWorkflow::WORKFLOW_NAME);
    }

    public function kickoff(object $package): void
    {
        if ($package->marking !== PackagePlace::Discovered) {
            return;
        }
        $this->continueAfterFlush($package);
    }

    /** Also supports direct native apply() callers, including the legacy CLI. */
    public function continueAfterFlush(object $package, array $context = [], ?\Symfony\Component\Workflow\Transition $previous = null): void
    {
        if ( ($context['cascade'] ?? null) === 'none') {
            return;
        }
        if ($this->sync) $context['cascade'] = 'sync';
        $this->next->continue($this->workflow, $package, $context, $previous);
    }

    public static function reference(Package $package): SubjectReference
    {
        return new SubjectReference('package', $package->id);
    }

    private function requireCommittedBoundary(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            throw new \LogicException('Package workflow dispatch requires a committed transaction; use an outbox for transactional producers.');
        }
    }
}
