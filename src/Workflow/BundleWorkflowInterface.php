<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Package;
use Survos\WorkflowExtrasBundle\Attribute\Transition;
use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;

#[AsWorkflow(supports: [Package::class], name: self::WORKFLOW_NAME, places: PackagePlace::class, description: 'Load and validate package metadata, then fetch repository documentation')]
class BundleWorkflowInterface
{
    // This name is used for injecting the workflow into a controller!
    public const WORKFLOW_NAME = 'BundleWorkflow';

    // Settled places can be reloaded: PackagistChangesRefresher dispatches load for
    // packages Packagist reports as updated, and the chain re-evaluates from there.
    #[Transition(PackagePlace::New, PackagePlace::ComposerLoaded, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    #[Transition(PackagePlace::SymfonyOkay, PackagePlace::ComposerLoaded, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    #[Transition(PackagePlace::SymfonyOutdated, PackagePlace::ComposerLoaded, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    #[Transition(PackagePlace::OutdatedPhp, PackagePlace::ComposerLoaded, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    #[Transition(PackagePlace::Abandoned, PackagePlace::ComposerLoaded, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    #[Transition(PackagePlace::ValidRequirements, PackagePlace::ComposerLoaded, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    #[Transition(PackagePlace::Documented, PackagePlace::ComposerLoaded, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    final public const TRANSITION_LOAD = 'load';

    #[Transition(PackagePlace::ComposerLoaded, PackagePlace::Abandoned, guard: 'subject.isAbandoned',
        description: "Abandoned")]
    final public const TRANSITION_ABANDON = 'abandon';

    #[Transition(PackagePlace::PhpOkay, PackagePlace::ValidRequirements,
        'Validate supported requirements',
        guard: "subject.hasValidPhpVersion and not subject.isAbandoned and (subject.type != 'symfony-bundle' or subject.hasValidSymfonyVersion)")]
    #[Transition(PackagePlace::SymfonyOkay, PackagePlace::ValidRequirements,
        'Validate supported requirements',
        guard: "subject.hasValidPhpVersion and not subject.isAbandoned and (subject.type != 'symfony-bundle' or subject.hasValidSymfonyVersion)")]
    final public const TRANSITION_VALID = 'valid';

    #[Transition(PackagePlace::ComposerLoaded, PackagePlace::OutdatedPhp,
        guard: "not subject.isAbandoned and subject.hasValidPhpVersion === false",
        description: "Not abandoned; unsupported PHP")]
    final public const TRANSITION_PHP_TOO_OLD = 'php_too_old';

    #[Transition(PackagePlace::ComposerLoaded, PackagePlace::PhpOkay,
        guard: "not subject.isAbandoned and subject.hasValidPhpVersion",
        description: "Not abandoned; compatible PHP")]
    final public const TRANSITION_PHP_OKAY = 'php_okay';

    #[Transition(PackagePlace::PhpOkay, PackagePlace::SymfonyOutdated,
        guard: "subject.type == 'symfony-bundle' and subject.hasValidSymfonyVersion === false",
        description: "Bundle without Symfony 8 support")]
    final public const TRANSITION_OUTDATED = 'symfony_outdated';

    #[Transition(PackagePlace::PhpOkay, PackagePlace::SymfonyOkay,
        guard: "subject.type == 'symfony-bundle' and subject.hasValidSymfonyVersion",
        description: "Symfony 8 compatible bundle")]
    final public const TRANSITION_SYMFONY_OKAY = 'symfony_okay';

    #[Transition(PackagePlace::ValidRequirements, PackagePlace::Documented,
        description: "README.md and AGENTS.md from the source repo at the loaded reference",
        guard: "subject.type == 'symfony-bundle' and subject.hasValidSymfonyVersion",
        async: true, transport: 'bundle.fetch.docs')]
    final public const TRANSITION_FETCH_DOCS = 'fetch_docs';


}
