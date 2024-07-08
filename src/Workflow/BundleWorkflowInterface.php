<?php

namespace App\Workflow;

use Survos\WorkflowBundle\Attribute\Transition;
use Survos\WorkflowBundle\Attribute\Place;

interface BundleWorkflowInterface
{
    final const PLACE_NEW = 'new';
    final const PLACE_COMPOSER_LOADED = 'loaded';
    final const PLACE_OUTDATED = 'outdated_symfony';
    final const PLACE_OUTDATED_PHP = 'php_too_old';
    final const PLACE_ABANDONED = 'abandoned';
    final const PLACE_VALID_REQUIREMENTS = 'valid';

    #[Transition([self::PLACE_NEW], self::PLACE_COMPOSER_LOADED)]
    final const TRANSITION_LOAD = 'load';

    #[Transition([self::PLACE_NEW], self::PLACE_ABANDONED)]
    final const TRANSITION_ABANDON = 'abandon';

    #[Transition([self::PLACE_COMPOSER_LOADED], self::PLACE_VALID_REQUIREMENTS)]
    final const TRANSITION_VALID = 'valid';

    #[Transition([self::PLACE_COMPOSER_LOADED], self::PLACE_OUTDATED_PHP)]
    final const TRANSITION_PHP_TOO_OLD = 'php_too_old';

    #[Transition([self::PLACE_COMPOSER_LOADED], self::PLACE_OUTDATED)]
    final const TRANSITION_OUTDATED = 'symfony_outdated';

}
