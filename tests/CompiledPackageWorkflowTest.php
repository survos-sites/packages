<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Workflow\PackagePlace;
use App\Workflow\PackageWorkflow as WF;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\StateMachine;

final class CompiledPackageWorkflowTest extends KernelTestCase
{
    public function testAcceptanceRejectionAndDocsUseOneState(): void
    {
        self::bootKernel();
        $package = new Package('acme/component');
        $package->data = ['type' => 'library'];
        $package->setState('metadata_loaded');
        $package->phpVersions = ['8.4'];
        $package->symfonyVersions = [];
        $workflow = self::getContainer()->get('workflow.registry')->get($package, WF::WORKFLOW_NAME);
        self::assertTrue(self::getContainer()->has('state_machine.'.WF::WORKFLOW_NAME));
        self::assertSame(['accept', 'reject'], $workflow->getMetadataStore()->getPlaceMetadata('metadata_loaded')['next']);
        self::assertTrue($workflow->can($package, 'accept'));
        self::assertFalse($workflow->can($package, 'reject'));
        $workflow->apply($package, 'accept', ['cascade' => 'none']);
        self::assertSame(['accepted'], $package->places);
        self::assertFalse($workflow->can($package, 'fetch_docs'), 'Libraries finish at accepted.');
        $package->data['type'] = 'symfony-bundle';
        self::assertTrue($workflow->can($package, 'fetch_docs'));
        $package->setState('metadata_loaded');
        $package->rejectionReason = 'php_incompatible';
        self::assertFalse($workflow->can($package, 'accept'));
        self::assertTrue($workflow->can($package, 'reject'));
        $workflow->apply($package, 'reject', ['cascade' => 'none']);
        self::assertSame(['rejected'], $package->places);
        self::assertSame(['load'], array_map(static fn ($t) => $t->getName(), $workflow->getEnabledTransitions($package)));
        self::assertArrayNotHasKey('next', $workflow->getMetadataStore()->getPlaceMetadata('rejected'));
        self::assertFalse($workflow->can($package, 'fetch_docs'));
        $package->setState('documented');
        self::assertSame(['load'], array_map(static fn ($t) => $t->getName(), $workflow->getEnabledTransitions($package)));
    }
}
