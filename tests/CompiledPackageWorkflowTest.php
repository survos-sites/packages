<?php

declare(strict_types=1);

namespace App\Tests;

use App\Workflow\PackagePlace;

use App\Entity\Package;
use App\Workflow\PackageWorkflow as WF;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CompiledPackageWorkflowTest extends KernelTestCase
{
    public function testCompiledGuardsAndOrderedNextMetadata(): void
    {
        self::bootKernel();
        $package = new Package('acme/component');
        $package->data = ['type' => 'library'];
        $package->abandoned = true;
        $package->marking = PackagePlace::MetadataLoaded;
        $package->phpVersions = ['8.4'];
        $package->symfonyVersions = [];
        $workflow = self::getContainer()->get('workflow.registry')->get($package, WF::WORKFLOW_NAME);
        self::assertTrue(self::getContainer()->get('workflow.registry')->has($package, WF::WORKFLOW_NAME));
        self::assertFalse(self::getContainer()->get('workflow.registry')->has(new \stdClass(), WF::WORKFLOW_NAME));
        self::assertSame('Discovered', $workflow->getMetadataStore()->getPlaceMetadata('discovered')['label']);
        foreach ($workflow->getDefinition()->getPlaces() as $place) {
            self::assertNotEmpty($workflow->getMetadataStore()->getPlaceMetadata($place)['description']);
        }
        foreach ($workflow->getDefinition()->getTransitions() as $transition) {
            self::assertNotEmpty($workflow->getMetadataStore()->getTransitionMetadata($transition)['description']);
        }
        self::assertSame([WF::TRANSITION_ACCEPT_REQUIREMENTS, WF::TRANSITION_REJECT_REQUIREMENTS], $workflow->getMetadataStore()->getPlaceMetadata(PackagePlace::MetadataLoaded->value)['next']);
        self::assertTrue($workflow->can($package, WF::TRANSITION_ACCEPT_REQUIREMENTS));
        self::assertFalse($workflow->can($package, WF::TRANSITION_REJECT_REQUIREMENTS));
        $package->symfonyVersionString = '^7.4';
        self::assertFalse($workflow->can($package, WF::TRANSITION_ACCEPT_REQUIREMENTS));
        self::assertTrue($workflow->can($package, WF::TRANSITION_REJECT_REQUIREMENTS));
        $package->symfonyVersions = ['8.0'];
        self::assertTrue($workflow->can($package, WF::TRANSITION_ACCEPT_REQUIREMENTS));
        $package->data['type'] = 'symfony-bundle';
        $package->marking = PackagePlace::RequirementsCompatible;
        self::assertSame([WF::TRANSITION_FETCH_DOCS], $workflow->getMetadataStore()->getPlaceMetadata(PackagePlace::RequirementsCompatible->value)['next']);
        self::assertTrue($workflow->can($package, WF::TRANSITION_FETCH_DOCS));
        $package->marking = PackagePlace::Documented;
        self::assertSame([WF::TRANSITION_LOAD], array_map(static fn ($t) => $t->getName(), $workflow->getEnabledTransitions($package)));
    }
}
