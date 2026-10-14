<?php

declare(strict_types=1);

namespace App\Tests;

use App\Command\LoadDataCommand;
use App\Entity\Package;
use App\Repository\PackageRepository;
use App\Tests\Support\RemoteFixtures;
use App\Workflow\PackagePlace;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\CacheInterface;

/** LoadDataCommand only persists; the workflow bundles start each new package exactly once. */
final class PackageKickoffTest extends KernelTestCase
{
    public static function modes(): array
    {
        return ['queued' => [false], 'sync, with dependencies created during kickoff' => [true]];
    }

    #[DataProvider('modes')]
    public function testSetupStartsEachNewPackageOnce(bool $sync): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM '.Package::class.' p')->execute();
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn([RemoteFixtures::PACKAGE => (object) ['abandoned' => false, 'repository' => 'https://github.com/symfony/monolog-bundle']]);
        $command = new LoadDataCommand(self::getContainer()->get(PackageRepository::class), $em, $cache, self::getContainer()->get(TransitionExecutorInterface::class));

        self::assertSame(0, $command(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()), sync: $sync, batch: 1));

        $loads = array_map(static fn ($envelope) => $envelope->getMessage()->subject->id, array_filter(
            self::getContainer()->get('messenger.transport.bundle.load')->getSent(),
            static fn ($envelope) => $envelope->getMessage() instanceof TransitionRequest,
        ));
        self::assertSame(array_values(array_unique($loads)), array_values($loads), 'a package was started twice');
        $em->clear();
        $package = $em->find(Package::class, Package::idFromName(RemoteFixtures::PACKAGE));
        if ($sync) {
            self::assertSame([], $loads);
            self::assertSame(PackagePlace::Documented, $package->marking);
            // Dependencies persisted inside the sync chain were started and finished too.
            self::assertGreaterThan(1, $em->getRepository(Package::class)->count(['marking' => PackagePlace::RequirementsCompatible]));
        } else {
            self::assertSame([$package->id], array_values($loads));
            self::assertSame(PackagePlace::Discovered, $package->marking);
        }
    }
}
