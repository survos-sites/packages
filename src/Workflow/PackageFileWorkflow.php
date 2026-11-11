<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\PackageFile;
use App\Service\PackageFileExtractor;
use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;
use Survos\WorkflowMessengerBundle\Attribute\Transition as AsyncTransition;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\WorkflowType;

/**
 * A package's documents are separate subjects: fetch_docs stores one PackageFile per
 * document, and each one is read by its own queued transition.
 */
#[AsWorkflow(supports: [PackageFile::class], name: self::WORKFLOW_NAME, type: WorkflowType::StateMachine, markingProperty: 'state', places: PackageFilePlace::class, description: 'Extract the structure of one stored package document')]
final class PackageFileWorkflow
{
    public const WORKFLOW_NAME = 'PackageFileWorkflow';

    #[AsyncTransition(PackageFilePlace::Fetched, PackageFilePlace::Extracted, 'Extract headings and commands from the document', async: true, transport: 'bundle.extract')]
    public const TRANSITION_EXTRACT = 'extract';

    public function __construct(private readonly PackageFileExtractor $extractor)
    {
    }

    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_EXTRACT)]
    public function onExtract(TransitionEvent $event): void
    {
        /** @var PackageFile $file */
        $file = $event->getSubject();
        $file->extract = $this->extractor->extract($file);
    }
}
