<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Workflow\PackageWorkflow as WF;
use Survos\WorkflowExtrasBundle\Service\WorkflowCatalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkflowCatalogTest extends KernelTestCase
{
    public function testNativeWorkflowIsDiscoverableWithoutConstructingAnEntity(): void
    {
        self::bootKernel();
        $catalog = self::getContainer()->get(WorkflowCatalog::class);
        self::assertSame([WF::WORKFLOW_NAME], $catalog->forClass(Package::class));
        self::assertSame([], $catalog->forClass(\stdClass::class));
        self::assertSame([
            'supports' => [Package::class],
            'definition_class' => WF::class,
            'type' => 'state_machine',
        ], $catalog->all()[WF::WORKFLOW_NAME]);
        self::assertSame(WF::WORKFLOW_NAME, $catalog->get(WF::WORKFLOW_NAME)->getName());
        self::assertArrayNotHasKey('SurvosStateBundle', self::$kernel->getBundles());
    }

    public function testUnknownWorkflowHasAnExplicitError(): void
    {
        self::bootKernel();
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(WorkflowCatalog::class)->get('missing');
    }
}
