<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Package;
use Doctrine\ORM\EntityManagerInterface;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Survos\WorkflowMessengerBundle\Service\TransitionDispatcher;
use Survos\WorkflowExtrasBundle\Service\NextTransitionResolver;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/** App-owned persistence and ordered continuation policy around the native workflow. */
final class PackagePipeline implements \Survos\WorkflowExtrasBundle\Contract\WorkflowContinuationInterface
{
    public bool $sync = false;

    public function __construct(
        #[Target(PackageWorkflow::WORKFLOW_NAME)] private readonly WorkflowInterface $workflow,
        private readonly NextTransitionResolver $next,
        private readonly TransitionDispatcher $dispatcher,
        private readonly EntityManagerInterface $em,
    ) {}

    public function dispatch(Package $package, string $transition, array $context = []): void
    {
        $this->requireCommittedBoundary();
        if (!$this->sync && ($context['cascade'] ?? null) !== 'sync' && $this->isAsync($transition)) {
            $this->dispatcher->dispatch(PackageWorkflow::WORKFLOW_NAME, self::reference($package), $transition, $context);
            return;
        }
        if ($this->sync) {
            $context['cascade'] = 'sync';
        }
        $this->apply($package, $transition, $context);
    }

    public function apply(Package $package, string $transition, array $context = []): void
    {
        $this->requireCommittedBoundary();
        $this->workflow->apply($package, $transition, $context);
        $this->finish($package, $context);
    }

    /** Called by the async subject store only after apply() and all its listeners finish. */
    public function finish(Package $package, array $context = []): void
    {
        $this->requireCommittedBoundary();
        $pending = null;
        $visited = [];
        while ( ($context['cascade'] ?? null) !== 'none') {
            $candidates = $this->workflow->getMetadataStore()->getPlaceMetadata($package->marking->value)['next'] ?? [];
            $pending = $this->next->firstEnabled($this->workflow, $package, $candidates);
            if ($pending === null || (!$this->sync && ($context['cascade'] ?? null) !== 'sync' && $this->isAsync($pending))) {
                break;
            }
            $key = $package->marking->value.':'.$pending;
            if (isset($visited[$key])) {
                throw new \LogicException('A synchronous package continuation cycle was detected.');
            }
            $visited[$key] = true;
            $this->workflow->apply($package, $pending, $context);
            $pending = null;
        }
        // No workflow-event flush: all sync guards/listeners finish first. Dependency
        // insertions are flushed here too; their kickoff listener runs after commit.
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
    public function continueAfterFlush(object $package, array $context = []): void
    {
        if ( ($context['cascade'] ?? null) === 'none') {
            return;
        }
        $candidates = $this->workflow->getMetadataStore()->getPlaceMetadata($package->marking->value)['next'] ?? [];
        if (null !== $transition = $this->next->firstEnabled($this->workflow, $package, $candidates)) {
            $this->dispatch($package, $transition, $context);
        }
    }

    public static function reference(Package $package): SubjectReference
    {
        return new SubjectReference('package', $package->id);
    }

    private function isAsync(string $name): bool
    {
        foreach ($this->workflow->getDefinition()->getTransitions() as $transition) {
            if ($transition->getName() === $name) {
                return ($this->workflow->getMetadataStore()->getTransitionMetadata($transition)['async'] ?? false) === true;
            }
        }
        throw new \InvalidArgumentException('Unknown package transition: '.$name);
    }

    private function requireCommittedBoundary(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            throw new \LogicException('Package workflow dispatch requires a committed transaction; use an outbox for transactional producers.');
        }
    }
}
