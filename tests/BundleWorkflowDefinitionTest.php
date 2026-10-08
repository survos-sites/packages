<?php

declare(strict_types=1);

namespace App\Tests;

use App\Workflow\BundleWorkflowInterface as WF;
use PHPUnit\Framework\TestCase;
use Survos\StateBundle\Attribute\Transition;

final class BundleWorkflowDefinitionTest extends TestCase
{
    public function testDocumentedIsTerminalAndOnlyReachedFromValid(): void
    {
        $incoming = false;
        foreach ((new \ReflectionClass(WF::class))->getReflectionConstants() as $constant) {
            foreach ($constant->getAttributes(Transition::class) as $attribute) {
                $transition = $attribute->newInstance();
                self::assertNotContains(WF::PLACE_DOCUMENTED, (array) $transition->from);
                if (in_array(WF::PLACE_VALID_REQUIREMENTS, (array) $transition->from, true)) {
                    self::assertSame(WF::TRANSITION_FETCH_DOCS, $constant->getValue());
                }
                $incoming = $incoming || in_array(WF::PLACE_DOCUMENTED, (array) $transition->to, true);
            }
        }
        self::assertTrue($incoming, 'Documented must remain reachable.');
    }
    public function testBundleAndComponentTakeDifferentGuardedPaths(): void
    {
        $package = new \App\Entity\Package('acme/component');
        $package->data = ['type' => 'library'];
        $package->phpVersions = ['8.4'];
        $package->symfonyVersions = [];
        self::assertFalse($this->guard(WF::TRANSITION_SYMFONY_OKAY, $package));
        self::assertTrue($this->guard(WF::TRANSITION_VALID, $package));
        self::assertFalse($this->guard(WF::TRANSITION_OUTDATED, $package));

        $package->data = ['type' => 'symfony-bundle'];
        self::assertFalse($this->guard(WF::TRANSITION_VALID, $package));
        self::assertTrue($this->guard(WF::TRANSITION_OUTDATED, $package));
        $package->symfonyVersions = ['7.4'];
        self::assertFalse($package->hasValidSymfonyVersion);
        $package->symfonyVersions = ['8.0'];
        self::assertTrue($this->guard(WF::TRANSITION_SYMFONY_OKAY, $package));
        self::assertTrue($this->guard(WF::TRANSITION_VALID, $package));
        $package->phpVersions = [];
        self::assertFalse($this->guard(WF::TRANSITION_VALID, $package));
    }

    private function guard(string $name, \App\Entity\Package $subject): bool
    {
        foreach ((new \ReflectionClass(WF::class))->getReflectionConstants() as $constant) {
            if ($constant->getValue() !== $name) continue;
            $attributes = $constant->getAttributes(Transition::class);
            if (!$attributes) continue;
            return (bool) (new \Symfony\Component\ExpressionLanguage\ExpressionLanguage())->evaluate(
                $attributes[0]->newInstance()->guard, ['subject' => $subject]
            );
        }
        self::fail('Missing transition '.$name);
    }
}
