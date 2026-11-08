<?php

declare(strict_types=1);

namespace App\Tests;

use App\Workflow\PackagePlace;

use App\Workflow\PackageWorkflow as WF;
use PHPUnit\Framework\TestCase;
use Survos\WorkflowExtrasBundle\Attribute\Transition;

final class PackageWorkflowDefinitionTest extends TestCase
{
    public function testFivePlacesAndFiveTransitionNames(): void
    {
        self::assertSame(['discovered', 'metadata_loaded', 'accepted', 'rejected', 'documented'], array_column(PackagePlace::cases(), 'value'));
        $names = [];
        foreach ((new \ReflectionClass(WF::class))->getReflectionConstants() as $constant) {
            if ($constant->getAttributes(Transition::class, \ReflectionAttribute::IS_INSTANCEOF)) {
                $names[] = $constant->getValue();
            }
        }
        self::assertSame(['load', 'accept', 'reject', 'canonical_failed', 'fetch_docs'], $names);
    }

    public function testRequirementsDoNotDependOnPackageType(): void
    {
        $service = new \App\Service\PackageService(new \Psr\Log\NullLogger(), $this->createStub(\Symfony\Component\Serializer\SerializerInterface::class));
        foreach (['library', 'symfony-bundle'] as $type) {
            foreach ([[], ['symfony/config' => '^8.0'], ['symfony/config' => '^7.0'], ['php' => '^5.6']] as $requirements) {
                $package = new \App\Entity\Package('acme/example');
                $package->data = ['type' => $type, 'require' => $requirements];
                $service->populateFromComposerData($package);
                $expected = !in_array($requirements, [['symfony/config' => '^7.0'], ['php' => '^5.6']], true);
                self::assertSame($expected, $package->requirementsCompatible);
                self::assertSame(isset($requirements['symfony/config']) ? ($expected ? 'compatible' : 'incompatible') : 'not_applicable', $package->symfonyCompatibility);
            }
        }
    }
}
