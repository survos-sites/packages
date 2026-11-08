<?php

declare(strict_types=1);

namespace App\Workflow;

use Survos\WorkflowExtrasBundle\Attribute\Place;

/** One durable processing checkpoint at a time. Rejection details live on Package. */
enum PackagePlace: string
{
    #[Place('Package name discovered; metadata has not been loaded', initial: true, next: PackageWorkflow::TRANSITION_LOAD)]
    case Discovered = 'discovered';

    #[Place('Metadata request completed; accept or reject using the recorded criteria',
        next: [PackageWorkflow::TRANSITION_ACCEPT, PackageWorkflow::TRANSITION_REJECT])]
    case MetadataLoaded = 'metadata_loaded';

    #[Place('Accepted package; libraries finish here and Symfony bundles fetch docs', next: PackageWorkflow::TRANSITION_FETCH_DOCS)]
    case Accepted = 'accepted';

    #[Place('Rejected package; processing stops until an explicit metadata refresh')]
    case Rejected = 'rejected';

    #[Place('Accepted bundle whose README/AGENTS fetch was attempted; files may be absent', label: 'Docs fetched')]
    case Documented = 'documented';

    /** @return array<string, int> */
    public static function marking(self $place): array
    {
        return [$place->value => 1];
    }
}
