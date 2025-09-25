<?php

namespace App\Workflow;

use App\Command\LoadDataCommand;
use App\Entity\Package;
use App\Workflow\BundleWorkflowInterface as WF;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

#[Workflow(supports: [Package::class], name:self::WORKFLOW_NAME)]
class BundleWorkflowInterface
{
    // This name is used for injecting the workflow into a controller!
    public const WORKFLOW_NAME = 'BundleWorkflow';

    #[Place(initial: true,
        description: "load from " . LoadDataCommand::BASE_URL,
        info: "basic from app:load")]
    final public const PLACE_NEW = 'new';
    #[Place(info: "composer.json", description: "Loaded from /packages/%s.json on " . LoadDataCommand::BASE_URL,
        next: [self::TRANSITION_PHP_OKAY, self::TRANSITION_PHP_TOO_OLD]
    )]
    final public const string PLACE_COMPOSER_LOADED = 'composer_loaded';

    #[Place(info: "outdated symfony")]
    final public const PLACE_SYMFONY_OUTDATED = 'outdated_symfony';
    #[Place(info: "works with supported Symfony")]
    final public const PLACE_SYMFONY_OKAY = 'symfony_ok';

    #[Place(info: "outdated PHP")]
    final public const PLACE_OUTDATED_PHP = 'php_is_too_old';
    #[Place(
        info: "php okay",
        next: [self::TRANSITION_SYMFONY_OKAY, self::TRANSITION_OUTDATED]
    )]
    final public const PLACE_PHP_OKAY = 'php_ok';
    #[Place(info: "abandoned or misconfigured")]
    final public const PLACE_ABANDONED = 'abandoned';
//    final public const PLACE_NOT_FOUND = 'not_found';
    #[Place(info: "usable!")]
    final public const PLACE_VALID_REQUIREMENTS = 'valid';

    #[Transition(
        [self::PLACE_NEW, self::PLACE_SYMFONY_OKAY, self::PLACE_VALID_REQUIREMENTS],
        self::PLACE_COMPOSER_LOADED,
        description: "Slow but detailed API call",
        info: "details from packagist API",
        transport: 'load_composer'
    )]
    final public const TRANSITION_LOAD = 'load';

    #[Transition([self::PLACE_NEW], self::PLACE_ABANDONED, guard: 'subject.abandoned')]
    final public const TRANSITION_ABANDON = 'abandon';

    #[Transition(
        from: [self::PLACE_SYMFONY_OKAY],
        to: self::PLACE_VALID_REQUIREMENTS,
        guard: "subject.hasValidSymfonyVersion")
    ]
    final public const TRANSITION_VALID = 'valid';

    #[Transition([self::PLACE_COMPOSER_LOADED], self::PLACE_OUTDATED_PHP,
        info: "PHP < 8.1?",
        guard: "!subject.hasValidPhpVersion")]
    final public const TRANSITION_PHP_TOO_OLD = 'php_too_old';

    #[Transition([self::PLACE_COMPOSER_LOADED], self::PLACE_PHP_OKAY,
        info: "PHP >= 8.1?",
        guard: "subject.hasValidPhpVersion")]
    final public const TRANSITION_PHP_OKAY = 'php_okay';

    #[Transition([self::PLACE_PHP_OKAY], self::PLACE_SYMFONY_OUTDATED,
        info: "Symfony < 5.4?",
        guard: "!subject.hasValidSymfonyVersion")]
    final public const TRANSITION_OUTDATED = 'symfony_outdated';

    #[Transition([self::PLACE_PHP_OKAY], self::PLACE_SYMFONY_OKAY,
        info: "Symfony > 5.4",
        guard: "subject.hasValidSymfonyVersion")]
    final public const TRANSITION_SYMFONY_OKAY = 'symfony_okay';


}
