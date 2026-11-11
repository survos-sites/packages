<?php

declare(strict_types=1);

namespace App\Workflow;

use Survos\WorkflowExtrasBundle\Attribute\Place;

/** One durable checkpoint per document (README, AGENTS.md) of a package. */
enum PackageFilePlace: string
{
    #[Place('Document stored; its vector has not been generated', initial: true, next: PackageFileWorkflow::TRANSITION_EMBED)]
    case Fetched = 'fetched';

    #[Place('Document vector stored for semantic search', label: 'Embedded')]
    case Embedded = 'embedded';
}
