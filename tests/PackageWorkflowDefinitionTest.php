<?php

declare(strict_types=1);

namespace App\Tests;

use App\Workflow\PackagePlace;

use App\Workflow\PackageWorkflow as WF;
use PHPUnit\Framework\TestCase;
use Survos\WorkflowExtrasBundle\Attribute\Transition;

final class PackageWorkflowDefinitionTest extends TestCase
{
    public function testSettledPlacesLeaveOnlyByReloadAndDocumentedFollowsValid(): void
    {
        $incoming = false;
        foreach ((new \ReflectionClass(WF::class))->getReflectionConstants() as $constant) {
            foreach ($constant->getAttributes(Transition::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $transition = $attribute->newInstance();
                $name = $constant->getValue();
                if (in_array(PackagePlace::Documented, (is_array($transition->from) ? $transition->from : [$transition->from]), true)) {
                    self::assertSame(WF::TRANSITION_LOAD, $name, 'documented is settled: only a refresh leaves it.');
                }
                if (in_array(PackagePlace::RequirementsCompatible, (is_array($transition->from) ? $transition->from : [$transition->from]), true)) {
                    self::assertContains($name, [WF::TRANSITION_FETCH_DOCS, WF::TRANSITION_LOAD]);
                }
                $incoming = $incoming || in_array(PackagePlace::Documented, (is_array($transition->to) ? $transition->to : [$transition->to]), true);
            }
        }
        self::assertTrue($incoming, 'Documented must remain reachable.');
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
