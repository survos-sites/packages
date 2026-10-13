<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Package;
use Survos\WorkflowExtrasBundle\Attribute\Place;
use Survos\WorkflowExtrasBundle\Attribute\Transition;
use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;

#[AsWorkflow(supports: [Package::class], name: self::WORKFLOW_NAME, description: 'Load and validate package metadata, then fetch repository documentation')]
class BundleWorkflowInterface
{
    // This name is used for injecting the workflow into a controller!
    public const WORKFLOW_NAME = 'BundleWorkflow';

    #[Place('Package discovered; load metadata from Packagist', initial: true, metadata: ['label' => 'Discovered'],
        // Persisting a new package queues load once, after its insert is flushed.
        next: [self::TRANSITION_LOAD])]
    final public const PLACE_NEW = 'new';
    #[Place('Package metadata loaded from Packagist', metadata: ['label' => 'Metadata loaded'],
        next: [self::TRANSITION_ABANDON, self::TRANSITION_PHP_OKAY, self::TRANSITION_PHP_TOO_OLD]
    )]
    final public const string PLACE_COMPOSER_LOADED = 'composer_loaded';

    #[Place('Does not support Symfony 8', metadata: ['label' => 'Symfony unsupported'])]
    final public const PLACE_SYMFONY_OUTDATED = 'outdated_symfony';
    #[Place('Supports Symfony 8', metadata: ['label' => 'Symfony 8 compatible'], next: [self::TRANSITION_VALID])]
    final public const PLACE_SYMFONY_OKAY = 'symfony_ok';

    #[Place('Does not support a current PHP version', metadata: ['label' => 'PHP unsupported'])]
    final public const PLACE_OUTDATED_PHP = 'php_is_too_old';
    #[Place(
        'Package supports a current PHP version',
        metadata: ['label' => 'PHP compatible'],
        // Guards are exclusive: a bundle goes to symfony_ok or outdated_symfony, a library to valid.
        next: [self::TRANSITION_SYMFONY_OKAY, self::TRANSITION_OUTDATED, self::TRANSITION_VALID]
    )]
    final public const PLACE_PHP_OKAY = 'php_ok';
    #[Place('Abandoned or misconfigured package')]
    final public const PLACE_ABANDONED = 'abandoned';
    #[Place('Package requirements are supported', next: [self::TRANSITION_FETCH_DOCS])]
    final public const PLACE_VALID_REQUIREMENTS = 'valid';
    #[Place('README / AGENTS.md fetch attempted; either may be null', metadata: ['label' => 'Docs fetched'])]
    final public const PLACE_DOCUMENTED = 'documented';

    // Settled places can be reloaded: PackagistChangesRefresher dispatches load for
    // packages Packagist reports as updated, and the chain re-evaluates from there.
    #[Transition(self::PLACE_NEW, self::PLACE_COMPOSER_LOADED, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    #[Transition(self::PLACE_SYMFONY_OKAY, self::PLACE_COMPOSER_LOADED, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    #[Transition(self::PLACE_SYMFONY_OUTDATED, self::PLACE_COMPOSER_LOADED, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    #[Transition(self::PLACE_OUTDATED_PHP, self::PLACE_COMPOSER_LOADED, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    #[Transition(self::PLACE_ABANDONED, self::PLACE_COMPOSER_LOADED, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    #[Transition(self::PLACE_VALID_REQUIREMENTS, self::PLACE_COMPOSER_LOADED, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    #[Transition(self::PLACE_DOCUMENTED, self::PLACE_COMPOSER_LOADED, 'Fetch package metadata from Packagist',
        async: true, transport: 'bundle.load')]
    final public const TRANSITION_LOAD = 'load';

    #[Transition(self::PLACE_COMPOSER_LOADED, self::PLACE_ABANDONED, guard: 'subject.isAbandoned',
        description: "Abandoned")]
    final public const TRANSITION_ABANDON = 'abandon';

    #[Transition(self::PLACE_PHP_OKAY, self::PLACE_VALID_REQUIREMENTS,
        'Validate supported requirements',
        guard: "subject.hasValidPhpVersion and not subject.isAbandoned and (subject.type != 'symfony-bundle' or subject.hasValidSymfonyVersion)")]
    #[Transition(self::PLACE_SYMFONY_OKAY, self::PLACE_VALID_REQUIREMENTS,
        'Validate supported requirements',
        guard: "subject.hasValidPhpVersion and not subject.isAbandoned and (subject.type != 'symfony-bundle' or subject.hasValidSymfonyVersion)")]
    final public const TRANSITION_VALID = 'valid';

    #[Transition(self::PLACE_COMPOSER_LOADED, self::PLACE_OUTDATED_PHP,
        guard: "not subject.isAbandoned and subject.hasValidPhpVersion === false",
        description: "Not abandoned; unsupported PHP")]
    final public const TRANSITION_PHP_TOO_OLD = 'php_too_old';

    #[Transition(self::PLACE_COMPOSER_LOADED, self::PLACE_PHP_OKAY,
        guard: "not subject.isAbandoned and subject.hasValidPhpVersion",
        description: "Not abandoned; compatible PHP")]
    final public const TRANSITION_PHP_OKAY = 'php_okay';

    #[Transition(self::PLACE_PHP_OKAY, self::PLACE_SYMFONY_OUTDATED,
        guard: "subject.type == 'symfony-bundle' and subject.hasValidSymfonyVersion === false",
        description: "Bundle without Symfony 8 support")]
    final public const TRANSITION_OUTDATED = 'symfony_outdated';

    #[Transition(self::PLACE_PHP_OKAY, self::PLACE_SYMFONY_OKAY,
        guard: "subject.type == 'symfony-bundle' and subject.hasValidSymfonyVersion",
        description: "Symfony 8 compatible bundle")]
    final public const TRANSITION_SYMFONY_OKAY = 'symfony_okay';

    #[Transition(self::PLACE_VALID_REQUIREMENTS, self::PLACE_DOCUMENTED,
        description: "README.md and AGENTS.md from the source repo at the loaded reference",
        guard: "subject.type == 'symfony-bundle' and subject.hasValidSymfonyVersion",
        async: true, transport: 'bundle.fetch.docs')]
    final public const TRANSITION_FETCH_DOCS = 'fetch_docs';


}
