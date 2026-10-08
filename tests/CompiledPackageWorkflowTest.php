<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Workflow\BundleWorkflowInterface as WF;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CompiledPackageWorkflowTest extends KernelTestCase
{
    public function testCompiledGuardsAndOrderedNextMetadata(): void
    {
        self::bootKernel();
        $package = new Package('acme/component');
        $package->data = ['type' => 'library'];
        $package->marking = WF::PLACE_PHP_OKAY;
        $package->phpVersions = ['8.4'];
        $package->symfonyVersions = [];
        $workflow = self::getContainer()->get('workflow.registry')->get($package, WF::WORKFLOW_NAME);
        self::assertSame([WF::TRANSITION_SYMFONY_OKAY, WF::TRANSITION_VALID], $workflow->getMetadataStore()->getPlaceMetadata(WF::PLACE_PHP_OKAY)['next']);
        self::assertFalse($workflow->can($package, WF::TRANSITION_SYMFONY_OKAY));
        self::assertTrue($workflow->can($package, WF::TRANSITION_VALID));
        $package->data['type'] = 'symfony-bundle';
        self::assertFalse($workflow->can($package, WF::TRANSITION_VALID));
        self::assertTrue($workflow->can($package, WF::TRANSITION_OUTDATED));
        $package->symfonyVersions = ['7.4'];
        self::assertFalse($package->hasValidSymfonyVersion);
        $package->symfonyVersions = ['8.0'];
        self::assertTrue($workflow->can($package, WF::TRANSITION_SYMFONY_OKAY));
        $package->marking = WF::PLACE_VALID_REQUIREMENTS;
        self::assertSame([WF::TRANSITION_FETCH_DOCS], $workflow->getMetadataStore()->getPlaceMetadata(WF::PLACE_VALID_REQUIREMENTS)['next']);
        self::assertTrue($workflow->can($package, WF::TRANSITION_FETCH_DOCS));
        $package->marking = WF::PLACE_DOCUMENTED;
        self::assertSame([], $workflow->getEnabledTransitions($package));
    }
}
