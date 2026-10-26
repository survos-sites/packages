<?php

declare(strict_types=1);

namespace App\Workflow;

use Survos\WorkflowExtrasBundle\Attribute\Place;

/**
 * A package is in several places at once, one per independent track:
 *
 *  - metadata:       discovered, then metadata_loaded (stays until the next refresh)
 *  - requirements:   requirements_pending, then compatible or unsupported
 *  - documentation:  docs_pending, then documented or docs_skipped
 *
 * `load` forks into all three; a refresh clears the derived tracks and forks again.
 */
enum PackagePlace: string
{
    #[Place('Package discovered; load metadata from Packagist', icon: 'tabler:radar', iconClass: 'text-primary', initial: true, next: PackageWorkflow::TRANSITION_LOAD)]
    case Discovered = 'discovered';

    #[Place('Metadata loaded from Packagist. Stays marked until the next refresh.', icon: 'tabler:package', iconClass: 'text-info')]
    case MetadataLoaded = 'metadata_loaded';

    #[Place('Waiting to check PHP and declared Symfony core constraints, for every package regardless of type',
        icon: 'tabler:hourglass', iconClass: 'text-secondary', next: [PackageWorkflow::TRANSITION_ACCEPT_REQUIREMENTS, PackageWorkflow::TRANSITION_REJECT_REQUIREMENTS])]
    case RequirementsPending = 'requirements_pending';

    #[Place('PHP and applicable Symfony constraints are compatible. Missing constraints are not applicable, not failures.', icon: 'tabler:circle-check', iconClass: 'text-success')]
    case RequirementsCompatible = 'requirements_compatible';

    #[Place('A declared PHP or Symfony core constraint excludes supported versions; inspect the package compatibility results', icon: 'tabler:circle-x', iconClass: 'text-danger')]
    case RequirementsUnsupported = 'requirements_unsupported';

    #[Place('Waiting to fetch README / AGENTS.md; only compatible Symfony bundles have documentation fetched',
        icon: 'tabler:hourglass', iconClass: 'text-secondary', next: [PackageWorkflow::TRANSITION_FETCH_DOCS, PackageWorkflow::TRANSITION_SKIP_DOCS])]
    case DocsPending = 'docs_pending';

    #[Place('README / AGENTS.md fetch attempted; either may be absent', label: 'Docs fetched', icon: 'tabler:book', iconClass: 'text-success')]
    case Documented = 'documented';

    #[Place('Not a compatible Symfony bundle, so no documentation is fetched', label: 'Docs skipped', icon: 'tabler:book-off', iconClass: 'text-muted')]
    case DocsSkipped = 'docs_skipped';

    /**
     * A marking holding exactly these places, in the {place: 1} shape the workflow stores.
     *
     * @return array<string, int>
     */
    public static function marking(self ...$places): array
    {
        return array_fill_keys(array_map(static fn (self $place) => $place->value, $places), 1);
    }
}
