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
        $package->setMarking(PackagePlace::marking(PackagePlace::MetadataLoaded, PackagePlace::RequirementsPending, PackagePlace::DocsPending));
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
        self::assertSame([WF::TRANSITION_ACCEPT_REQUIREMENTS, WF::TRANSITION_REJECT_REQUIREMENTS], $workflow->getMetadataStore()->getPlaceMetadata(PackagePlace::RequirementsPending->value)['next']);
        self::assertTrue($workflow->can($package, WF::TRANSITION_ACCEPT_REQUIREMENTS));
        self::assertFalse($workflow->can($package, WF::TRANSITION_REJECT_REQUIREMENTS));
        $package->symfonyVersionString = '^7.4';
        self::assertFalse($workflow->can($package, WF::TRANSITION_ACCEPT_REQUIREMENTS));
        self::assertTrue($workflow->can($package, WF::TRANSITION_REJECT_REQUIREMENTS));
        $package->symfonyVersions = ['8.0'];
        self::assertTrue($workflow->can($package, WF::TRANSITION_ACCEPT_REQUIREMENTS));
        // Requirements and documentation are independent tracks: both are pending at once.
        self::assertSame([WF::TRANSITION_FETCH_DOCS, WF::TRANSITION_SKIP_DOCS], $workflow->getMetadataStore()->getPlaceMetadata(PackagePlace::DocsPending->value)['next']);
        self::assertTrue($workflow->can($package, WF::TRANSITION_SKIP_DOCS), 'a library has no docs to fetch');
        self::assertFalse($workflow->can($package, WF::TRANSITION_FETCH_DOCS));
        $package->data['type'] = 'symfony-bundle';
        self::assertTrue($workflow->can($package, WF::TRANSITION_FETCH_DOCS));
        self::assertFalse($workflow->can($package, WF::TRANSITION_SKIP_DOCS));

        // Applying one track leaves the other untouched.
        $workflow->apply($package, WF::TRANSITION_ACCEPT_REQUIREMENTS);
        self::assertTrue($package->isIn(PackagePlace::RequirementsCompatible));
        self::assertTrue($package->isIn(PackagePlace::DocsPending));
        self::assertFalse($package->isIn(PackagePlace::RequirementsPending));

        // Settled packages leave only by the one reload that matches their tracks.
        $package->setMarking(PackagePlace::marking(PackagePlace::MetadataLoaded, PackagePlace::RequirementsCompatible, PackagePlace::Documented));
        self::assertSame([WF::TRANSITION_RELOAD_DOCUMENTED], array_map(static fn ($t) => $t->getName(), $workflow->getEnabledTransitions($package)));
        $package->setMarking(PackagePlace::marking(PackagePlace::MetadataLoaded, PackagePlace::RequirementsUnsupported, PackagePlace::DocsSkipped));
        self::assertSame([WF::TRANSITION_RELOAD_UNSUPPORTED], array_map(static fn ($t) => $t->getName(), $workflow->getEnabledTransitions($package)));
        $package->setMarking(PackagePlace::marking(PackagePlace::MetadataLoaded, PackagePlace::RequirementsCompatible, PackagePlace::DocsPending));
        self::assertSame([WF::TRANSITION_FETCH_DOCS], array_map(static fn ($t) => $t->getName(), $workflow->getEnabledTransitions($package)), 'no refresh while a track is pending');
    }
}
