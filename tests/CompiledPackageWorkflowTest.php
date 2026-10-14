<?php

declare(strict_types=1);

namespace App\Tests;

use App\Workflow\PackagePlace;

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
        $package->marking = PackagePlace::PhpOkay->value;
        $package->phpVersions = ['8.4'];
        $package->symfonyVersions = [];
        $workflow = self::getContainer()->get('workflow.registry')->get($package, WF::WORKFLOW_NAME);
        self::assertSame([Package::class], self::getContainer()->get(\Survos\StateBundle\Service\WorkflowHelperService::class)->getSupports(WF::WORKFLOW_NAME));
        foreach ($workflow->getDefinition()->getPlaces() as $place) {
            self::assertNotEmpty($workflow->getMetadataStore()->getPlaceMetadata($place)['description']);
        }
        foreach ($workflow->getDefinition()->getTransitions() as $transition) {
            self::assertNotEmpty($workflow->getMetadataStore()->getTransitionMetadata($transition)['description']);
        }
        self::assertSame([WF::TRANSITION_SYMFONY_OKAY, WF::TRANSITION_OUTDATED, WF::TRANSITION_VALID], $workflow->getMetadataStore()->getPlaceMetadata(PackagePlace::PhpOkay->value)['next']);
        self::assertFalse($workflow->can($package, WF::TRANSITION_SYMFONY_OKAY));
        self::assertTrue($workflow->can($package, WF::TRANSITION_VALID));
        $package->data['type'] = 'symfony-bundle';
        self::assertFalse($workflow->can($package, WF::TRANSITION_VALID));
        self::assertTrue($workflow->can($package, WF::TRANSITION_OUTDATED));
        $package->symfonyVersions = ['7.4'];
        self::assertFalse($package->hasValidSymfonyVersion);
        $package->symfonyVersions = ['8.0'];
        self::assertTrue($workflow->can($package, WF::TRANSITION_SYMFONY_OKAY));
        $package->marking = PackagePlace::ValidRequirements->value;
        self::assertSame([WF::TRANSITION_FETCH_DOCS], $workflow->getMetadataStore()->getPlaceMetadata(PackagePlace::ValidRequirements->value)['next']);
        self::assertTrue($workflow->can($package, WF::TRANSITION_FETCH_DOCS));
        $package->marking = PackagePlace::Documented->value;
        self::assertSame([WF::TRANSITION_LOAD], array_map(static fn ($t) => $t->getName(), $workflow->getEnabledTransitions($package)));
    }
}
