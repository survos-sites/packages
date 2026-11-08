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
    public function testAcceptProbesBeforeNextGuardWithoutApplyingRejectionItself(): void
    {
        self::bootKernel();
        $package = new Package('yaboroda/ybsy-file-bundle');
        $package->state = 'metadata_loaded';
        $package->data = ['type' => 'symfony-bundle'];
        $package->phpVersions = ['8.4'];
        $package->symfonyVersions = [];
        $package->canonicalUrl = 'https://bitbucket.org/yaboroda/ybsy-file-bundle';
        $workflow = self::getContainer()->get('workflow.registry')->get($package, WF::WORKFLOW_NAME);
        $workflow->apply($package, 'accept', ['cascade' => 'none']);
        self::assertSame('accepted', $package->state);
        self::assertSame(404, $package->canonicalProbe['status']);
        self::assertNull($package->rejectionReason);
        self::assertTrue($workflow->can($package, 'canonical_failed'));
        self::assertFalse($workflow->can($package, 'fetch_docs'));
    }

    public function testCanonicalFailureGuardUsesStoredResultAndBlocksDocs(): void
    {
        self::bootKernel();
        $package = new Package('example/deleted');
        $package->data = ['type' => 'symfony-bundle'];
        $package->state = 'accepted';
        $workflow = self::getContainer()->get('workflow.registry')->get($package, WF::WORKFLOW_NAME);
        self::assertSame(['canonical_failed', 'fetch_docs'], $workflow->getMetadataStore()->getPlaceMetadata('accepted')['next']);
        self::assertFalse($workflow->can($package, 'canonical_failed'));
        self::assertFalse($workflow->can($package, 'fetch_docs'));
        $package->canonicalProbe = ['outcome' => 'unavailable', 'status' => 404];
        self::assertTrue($workflow->can($package, 'canonical_failed'));
        self::assertFalse($workflow->can($package, 'fetch_docs'));
        $workflow->apply($package, 'canonical_failed', ['cascade' => 'none']);
        self::assertSame('rejected', $package->state);
        self::assertSame('canonical_unavailable', $package->rejectionReason);
    }

    public function testAcceptanceRejectionAndDocsUseOneState(): void
    {
        self::bootKernel();
        $package = new Package('acme/component');
        $package->data = ['type' => 'library'];
        $package->canonicalUrl = 'https://github.com/symfony/monolog-bundle';
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
