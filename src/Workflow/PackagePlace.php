<?php

declare(strict_types=1);

namespace App\Workflow;

use Survos\WorkflowExtrasBundle\Attribute\Place;

enum PackagePlace: string
{
    #[Place('Package discovered; load metadata from Packagist', icon: 'tabler:radar', iconClass: 'text-primary', initial: true, next: PackageWorkflow::TRANSITION_LOAD)]
    case Discovered = 'discovered';

    #[Place('Metadata loaded; check PHP and declared Symfony core constraints for every package, regardless of type',
        icon: 'tabler:package', iconClass: 'text-info', next: [PackageWorkflow::TRANSITION_ACCEPT_REQUIREMENTS, PackageWorkflow::TRANSITION_REJECT_REQUIREMENTS])]
    case MetadataLoaded = 'metadata_loaded';

    #[Place('PHP and applicable Symfony constraints are compatible. Missing constraints are not applicable, not failures. Documentation is fetched for bundles only.', icon: 'tabler:circle-check', iconClass: 'text-success', next: PackageWorkflow::TRANSITION_FETCH_DOCS)]
    case RequirementsCompatible = 'requirements_compatible';

    #[Place('A declared PHP or Symfony core constraint excludes supported versions; inspect the package compatibility results', icon: 'tabler:circle-x', iconClass: 'text-danger')]
    case RequirementsUnsupported = 'requirements_unsupported';

    #[Place('README / AGENTS.md fetch attempted; either may be absent', label: 'Docs fetched', icon: 'tabler:book', iconClass: 'text-success')]
    case Documented = 'documented';
}
