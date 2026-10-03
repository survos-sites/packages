<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Command\LoadDataCommand;
use App\Entity\Package;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

#[Workflow(supports: [Package::class], name:self::WORKFLOW_NAME)]
class BundleWorkflowInterface
{
    // This name is used for injecting the workflow into a controller!
    public const WORKFLOW_NAME = 'BundleWorkflow';

    #[Place(initial: true, metadata: ['label' => 'Discovered'],
        description: "load from " . LoadDataCommand::BASE_URL,
        info: "basic from app:load",
        // Persisting a new package queues load once, after its insert is flushed.
        next: [self::TRANSITION_LOAD])]
    final public const PLACE_NEW = 'new';
    #[Place(metadata: ['label' => 'Metadata loaded'], info: "composer.json", description: "Loaded from /packages/%s.json on " . LoadDataCommand::BASE_URL,
        next: [self::TRANSITION_ABANDON, self::TRANSITION_PHP_OKAY, self::TRANSITION_PHP_TOO_OLD]
    )]
    final public const string PLACE_COMPOSER_LOADED = 'composer_loaded';

    #[Place(metadata: ['label' => 'Symfony unsupported'], info: "Does not support Symfony 8")]
    final public const PLACE_SYMFONY_OUTDATED = 'outdated_symfony';
    #[Place(metadata: ['label' => 'Symfony 8 compatible'], info: "Supports Symfony 8", next: [self::TRANSITION_VALID])]
    final public const PLACE_SYMFONY_OKAY = 'symfony_ok';

    #[Place(metadata: ['label' => 'PHP unsupported'], info: "outdated PHP")]
    final public const PLACE_OUTDATED_PHP = 'php_is_too_old';
    #[Place(
        metadata: ['label' => 'PHP compatible'],
        info: "php okay",
        next: [self::TRANSITION_SYMFONY_OKAY, self::TRANSITION_VALID]
    )]
    final public const PLACE_PHP_OKAY = 'php_ok';
    #[Place(info: "abandoned or misconfigured")]
    final public const PLACE_ABANDONED = 'abandoned';
//    final public const PLACE_NOT_FOUND = 'not_found';
    #[Place(info: "usable!")]
    final public const PLACE_VALID_REQUIREMENTS = 'valid';

    #[Transition(
        [self::PLACE_NEW, self::PLACE_SYMFONY_OKAY],
        self::PLACE_COMPOSER_LOADED,
        description: "Slow but detailed API call",
        info: "details from packagist API",
        async: true
    )]
    final public const TRANSITION_LOAD = 'load';

    #[Transition([self::PLACE_COMPOSER_LOADED], self::PLACE_ABANDONED, guard: 'subject.isAbandoned',
        guardLabel: "Abandoned")]
    final public const TRANSITION_ABANDON = 'abandon';

    #[Transition(
        from: [self::PLACE_PHP_OKAY, self::PLACE_SYMFONY_OKAY],
        to: self::PLACE_VALID_REQUIREMENTS,
        guard: "subject.hasValidPhpVersion and not subject.isAbandoned and (subject.type != 'symfony-bundle' or subject.hasValidSymfonyVersion)",
        guardLabel: "Not abandoned; compatible PHP; Symfony 8 if a bundle")
    ]
    final public const TRANSITION_VALID = 'valid';

    #[Transition([self::PLACE_COMPOSER_LOADED], self::PLACE_OUTDATED_PHP,
        info: "No supported PHP version",
        guard: "not subject.isAbandoned and subject.hasValidPhpVersion === false",
        guardLabel: "Not abandoned; unsupported PHP")]
    final public const TRANSITION_PHP_TOO_OLD = 'php_too_old';

    #[Transition([self::PLACE_COMPOSER_LOADED], self::PLACE_PHP_OKAY,
        info: "Supports a current PHP version",
        guard: "not subject.isAbandoned and subject.hasValidPhpVersion",
        guardLabel: "Not abandoned; compatible PHP")]
    final public const TRANSITION_PHP_OKAY = 'php_okay';

    #[Transition([self::PLACE_PHP_OKAY], self::PLACE_SYMFONY_OUTDATED,
        info: "Bundle does not support Symfony 8",
        guard: "subject.type == 'symfony-bundle' and subject.hasValidSymfonyVersion === false",
        guardLabel: "Bundle without Symfony 8 support")]
    final public const TRANSITION_OUTDATED = 'symfony_outdated';

    #[Transition([self::PLACE_PHP_OKAY], self::PLACE_SYMFONY_OKAY,
        info: "Bundle supports Symfony 8",
        guard: "subject.type == 'symfony-bundle' and subject.hasValidSymfonyVersion",
        guardLabel: "Symfony 8 compatible bundle")]
    final public const TRANSITION_SYMFONY_OKAY = 'symfony_okay';


}
