<?php

namespace App\Workflow;

use Survos\WorkflowBundle\Attribute\Transition;

interface BundleWorkflowInterface
{
    final public const PLACE_NEW = 'new';
    final public const string PLACE_COMPOSER_LOADED = 'composer_loaded';
    final public const PLACE_SYMFONY_OUTDATED = 'outdated_symfony';
    final public const PLACE_SYMFONY_OKAY = 'symfony_ok';
    final public const PLACE_OUTDATED_PHP = 'php_is_too_old';
    final public const PLACE_PHP_OKAY = 'php_ok';
    final public const PLACE_ABANDONED = 'abandoned';
    final public const PLACE_NOT_FOUND = 'not_found';
    final public const PLACE_VALID_REQUIREMENTS = 'valid';

    #[Transition([self::PLACE_NEW], self::PLACE_COMPOSER_LOADED)]
    final public const TRANSITION_LOAD = 'load';

    #[Transition([self::PLACE_NEW], self::PLACE_ABANDONED)]
    final public const TRANSITION_ABANDON = 'abandon';

    #[Transition([self::PLACE_SYMFONY_OKAY], self::PLACE_VALID_REQUIREMENTS)]
    final public const TRANSITION_VALID = 'valid';

    #[Transition([self::PLACE_COMPOSER_LOADED], self::PLACE_OUTDATED_PHP)]
    final public const TRANSITION_PHP_TOO_OLD = 'php_too_old';

    #[Transition([self::PLACE_COMPOSER_LOADED], self::PLACE_PHP_OKAY)]
    final public const TRANSITION_PHP_OKAY = 'php_okay';

    #[Transition([self::PLACE_PHP_OKAY], self::PLACE_SYMFONY_OUTDATED)]
    final public const TRANSITION_OUTDATED = 'symfony_outdated';

    #[Transition([self::PLACE_PHP_OKAY], self::PLACE_SYMFONY_OKAY)]
    final public const TRANSITION_SYMFONY_OKAY = 'symfony_okay';
}
