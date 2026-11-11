<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\PackageFile;
use App\Service\PackageEmbeddingService;
use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;
use Survos\WorkflowMessengerBundle\Attribute\Transition as AsyncTransition;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\WorkflowType;

/**
 * A package's documents are separate subjects: fetch_docs stores one PackageFile per
 * document, and each one is embedded by its own queued transition.
 */
#[AsWorkflow(supports: [PackageFile::class], name: self::WORKFLOW_NAME, type: WorkflowType::StateMachine, markingProperty: 'state', places: PackageFilePlace::class, description: 'Embed one stored package document')]
final class PackageFileWorkflow
{
    public const WORKFLOW_NAME = 'PackageFileWorkflow';

    #[AsyncTransition(PackageFilePlace::Fetched, PackageFilePlace::Embedded, 'Persist an embedding of the document', async: true, transport: 'bundle.embed')]
    public const TRANSITION_EMBED = 'embed';

    public function __construct(private readonly PackageEmbeddingService $embeddings)
    {
    }

    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_EMBED)]
    public function onEmbed(TransitionEvent $event): void
    {
        try {
            $this->embeddings->vectorizeFile($event->getSubject());
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $error) {
            // A live HTTP response cannot cross the parallel worker channel; keep the diagnostic only.
            throw new \RuntimeException($error->getMessage(), (int) $error->getCode());
        }
    }
}
