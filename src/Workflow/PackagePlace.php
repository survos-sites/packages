<?php

declare(strict_types=1);

namespace App\Workflow;

use Survos\WorkflowExtrasBundle\Attribute\Place;

/** Places in the package workflow; backing values are persisted marking identifiers. */
enum PackagePlace: string
{
    #[Place('Package discovered; load metadata from Packagist', initial: true, label: 'Discovered',
        // Persisting a new package queues load once, after its insert is flushed.
        next: BundleWorkflowInterface::TRANSITION_LOAD)]
    case New = 'new';
    #[Place('Package metadata loaded from Packagist', label: 'Metadata loaded',
        next: [BundleWorkflowInterface::TRANSITION_ABANDON, BundleWorkflowInterface::TRANSITION_PHP_OKAY, BundleWorkflowInterface::TRANSITION_PHP_TOO_OLD]
    )]
    case ComposerLoaded = 'composer_loaded';

    #[Place('Does not support Symfony 8', label: 'Symfony unsupported')]
    case SymfonyOutdated = 'outdated_symfony';
    #[Place('Supports Symfony 8', label: 'Symfony 8 compatible', next: BundleWorkflowInterface::TRANSITION_VALID)]
    case SymfonyOkay = 'symfony_ok';

    #[Place('Does not support a current PHP version', label: 'PHP unsupported')]
    case OutdatedPhp = 'php_is_too_old';
    #[Place(
        'Package supports a current PHP version',
        label: 'PHP compatible',
        // Guards are exclusive: a bundle goes to symfony_ok or outdated_symfony, a library to valid.
        next: [BundleWorkflowInterface::TRANSITION_SYMFONY_OKAY, BundleWorkflowInterface::TRANSITION_OUTDATED, BundleWorkflowInterface::TRANSITION_VALID]
    )]
    case PhpOkay = 'php_ok';
    #[Place('Abandoned or misconfigured package')]
    case Abandoned = 'abandoned';
    #[Place('Package requirements are supported', next: BundleWorkflowInterface::TRANSITION_FETCH_DOCS)]
    case ValidRequirements = 'valid';
    #[Place('README / AGENTS.md fetch attempted; either may be null', label: 'Docs fetched')]
    case Documented = 'documented';
}
