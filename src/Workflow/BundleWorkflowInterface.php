<?php

namespace App\Workflow;

use Survos\WorkflowBundle\Attribute\Place;
use Survos\WorkflowBundle\Attribute\Transition;

interface BundleWorkflowInterface
{
    #[Place(initial: true, info: "entity in database")]
    final public const PLACE_NEW = 'new';
    final public const string PLACE_COMPOSER_LOADED = 'composer_loaded';
    #[Place(info: "outdated symfony")]
    final public const PLACE_SYMFONY_OUTDATED = 'outdated_symfony';
    #[Place(info: "works with supported Symfony")]
    final public const PLACE_SYMFONY_OKAY = 'symfony_ok';

    final public const PLACE_OUTDATED_PHP = 'php_is_too_old';
    final public const PLACE_PHP_OKAY = 'php_ok';
    #[Place(info: "abandoned or misconfigured")]
    final public const PLACE_ABANDONED = 'abandoned';
//    final public const PLACE_NOT_FOUND = 'not_found';
    #[Place(info: "usable!")]
    final public const PLACE_VALID_REQUIREMENTS = 'valid';

    #[Transition([self::PLACE_NEW, self::PLACE_SYMFONY_OKAY, self::PLACE_VALID_REQUIREMENTS], self::PLACE_COMPOSER_LOADED)]
    final public const TRANSITION_LOAD = 'load';

    #[Transition([self::PLACE_NEW], self::PLACE_ABANDONED, guard: 'subject.abandoned')]
    final public const TRANSITION_ABANDON = 'abandon';

    #[Transition(
        from: [self::PLACE_SYMFONY_OKAY],
        to: self::PLACE_VALID_REQUIREMENTS,
        guard: "subject.hasValidSymfonyVersion()")
    ]
    final public const TRANSITION_VALID = 'valid';

    #[Transition([self::PLACE_COMPOSER_LOADED], self::PLACE_OUTDATED_PHP,
        info: "PHP < 8.1?",
        guard: "!subject.hasValidPhpVersion()")]
    final public const TRANSITION_PHP_TOO_OLD = 'php_too_old';

    #[Transition([self::PLACE_COMPOSER_LOADED], self::PLACE_PHP_OKAY,
        info: "PHP >= 8.1?",
        guard: "subject.hasValidPhpVersion()")]
    final public const TRANSITION_PHP_OKAY = 'php_okay';

    #[Transition([self::PLACE_PHP_OKAY], self::PLACE_SYMFONY_OUTDATED,
        info: "Symfony < 5.4?",
        guard: "!subject.hasValidSymfonyVersion()")]
    final public const TRANSITION_OUTDATED = 'symfony_outdated';

    #[Transition([self::PLACE_PHP_OKAY], self::PLACE_SYMFONY_OKAY,
        info: "Symfony > 5.4",
        guard: "subject.hasValidSymfonyVersion()")]
    final public const TRANSITION_SYMFONY_OKAY = 'symfony_okay';


}
