<?php

declare(strict_types=1);

namespace App\Workflow;

use Survos\WorkflowExtrasBundle\Attribute\Place;

/** One durable checkpoint per document (README, AGENTS.md) of a package. */
enum PackageFilePlace: string
{
    #[Place('Document stored; nothing extracted yet', initial: true, next: PackageFileWorkflow::TRANSITION_EXTRACT)]
    case Fetched = 'fetched';

    #[Place('Headings and commands extracted', label: 'Extracted')]
    case Extracted = 'extracted';
}
