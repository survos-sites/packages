<?php

declare(strict_types=1);

namespace App\Workflow;

use Survos\WorkflowExtrasBundle\Attribute\Place;

enum PackagePlace: string
{
    #[Place('Package discovered; load metadata from Packagist', initial: true, next: PackageWorkflow::TRANSITION_LOAD)]
    case Discovered = 'discovered';

    #[Place('Metadata loaded; check PHP and declared Symfony core constraints for every package, regardless of type',
        next: [PackageWorkflow::TRANSITION_ACCEPT_REQUIREMENTS, PackageWorkflow::TRANSITION_REJECT_REQUIREMENTS])]
    case MetadataLoaded = 'metadata_loaded';

    #[Place('PHP and applicable Symfony constraints are compatible. Missing constraints are not applicable, not failures. Documentation is fetched for bundles only.', next: PackageWorkflow::TRANSITION_FETCH_DOCS)]
    case RequirementsCompatible = 'requirements_compatible';

    #[Place('A declared PHP or Symfony core constraint excludes supported versions; inspect the package compatibility results')]
    case RequirementsUnsupported = 'requirements_unsupported';

    #[Place('README / AGENTS.md fetch attempted; either may be absent', label: 'Docs fetched')]
    case Documented = 'documented';
}
