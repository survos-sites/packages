<?php

namespace App\Workflow;

use App\Entity\Package;
use App\Message\FetchComposer;
use App\Repository\PackageRepository;
use App\Service\PackageService;
use Doctrine\ORM\EntityManagerInterface;
use Packagist\Api\Client;
use Packagist\Api\Result\Package as PackagistPackage;
use Psr\Log\LoggerInterface;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsGuardListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\WorkflowInterface;
use App\Workflow\BundleWorkflowInterface as WF;

final class BundleWorkflow
{
    public function __construct(
        private MessageBusInterface $bus,
        private UrlGeneratorInterface $urlGenerator,
        private SerializerInterface $serializer,
        private LoggerInterface $logger,
        private PackageService $packageService,
        private EntityManagerInterface $entityManager,
        private PackageRepository $packageRepository,
        private Client $packagistClient,
        #[Target(WF::WORKFLOW_NAME)] private WorkflowInterface $workflow,
    ) {
    }


    /**
     * Handle binary checking of Symfony.
     */
//    #[AsGuardListener(WF::WORKFLOW_NAME)]
    public function onGuardSymfony(GuardEvent $event): void
    {
        $transitionName = $event->getTransition()->getName();
        $package = $this->getPackage($event);
        $validVersionCount = !empty($package->symfonyVersions);
        if (!in_array($transitionName, [WF::TRANSITION_SYMFONY_OKAY, WF::TRANSITION_OUTDATED])) {
            return;
        }
        match ($transitionName) {
            WF::TRANSITION_SYMFONY_OKAY => $event->setBlocked(0 === $validVersionCount, 'block if no valid versions'),
            WF::TRANSITION_OUTDATED => $event->setBlocked($validVersionCount > 0, 'block if we have valid versions'),
        };
        //        dd($transitionName,$package->getSymfonyVersions(), $package->getPhpVersions(), $package->getPhpVersionString(), $event->getTransitionBlockerList());
    }

//    #[AsGuardListener(WF::WORKFLOW_NAME)]
    public function onGuardPhp(GuardEvent $event): void
    {
        $composer = $this->getComposer($package = $this->getPackage($event));
        $transition = $event->getTransition();
        $transitionName = $event->getTransition()->getName();
        //        if (empty($composer)) {
        //        }
        //        $package = $this->getPackage($event);
        //        dd($composer, $package);
        if (empty($composer)) {
            switch ($transitionName) {
                case WF::TRANSITION_ABANDON:
                case WF::TRANSITION_LOAD:
                    // okay
                    break;
                default:
                    $event->setBlocked(true, 'Composer data is not yet loaded.');
            }
        }

        if (!in_array($transitionName, [WF::TRANSITION_PHP_OKAY, WF::TRANSITION_PHP_TOO_OLD])) {
            return;
        }

        $validPhpVersions = $package->phpVersions;
        switch ($event->getTransition()->getName()) {
            case WF::TRANSITION_PHP_TOO_OLD:
                if (count($validPhpVersions) > 0) {
                    // block the PHP_TOO_OLD transition
                    $event->setBlocked(true, 'block too old, because Valid PHP versions.');
                }
                break;
            case WF::TRANSITION_PHP_OKAY:
                if (0 === count($validPhpVersions)) {
                    $event->setBlocked(true, 'block okay, because No Valid PHP versions.');
                }
        }
    }

    private function getPackage(Event $event): Package
    {
        /* @var Package */
        return $event->getSubject();
    }

    private function getComposer(Package $package): ?array
    {
        return $package->data;
    }

//    #[AsCompletedListener(WF::WORKFLOW_NAME, WF::TRANSITION_LOAD)]
//    public function onLoadCompleted(CompletedEvent $event): void
//    {
//        $package = $this->getPackage($event);
//        foreach ([WF::TRANSITION_PHP_TOO_OLD, WF::TRANSITION_PHP_OKAY] as $transitionName) {
//            if ($this->workflow->can($package, $transitionName)) {
//                $this->workflow->apply($package, $transitionName);
//            }
//        }
//    }

//    #[AsCompletedListener(WF::WORKFLOW_NAME, WF::TRANSITION_PHP_OKAY)]
//    public function onPhpOkayCompleted(CompletedEvent $event): void
//    {
//        $package = $this->getPackage($event);
//        foreach ([WF::TRANSITION_OUTDATED, WF::TRANSITION_SYMFONY_OKAY] as $transitionName) {
//            if ($this->workflow->can($package, $transitionName)) {
//                $this->workflow->apply($package, $transitionName);
//            }
//        }
//    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_LOAD)]
    public function onLoadComposer(TransitionEvent $event): void
    {
        $package = $this->getPackage($event);
        // @todo: check updatedAt
        // https://packagist.org/apidoc#track-package-updates
        if (true || !$data = $package->data) {
            $this->loadLatestVersionData($package);
        }
        $this->packageService->populateFromComposerData($package);
    }

    //    #[Cache('1 day')]
    private function loadLatestVersionData(Package $package)
    {
        $packageName = $package->name;
        try {
            $composer = $this->packagistClient->getComposer($packageName);
        } catch (\Exception $exception) {
            $this->logger->error($packageName.' '.$exception->getMessage());

            return; // @todo: not_found state/
            throw $exception;
        }
        // slower, but more data
        /**
         * @var PackagistPackage $packagistPackage
         */
        $packagistPackage = $this->packagistClient->get($packageName);

        // get the default branch
        $versionCode = null;
        foreach ($packagistPackage->getVersions() as $version) {
            if ($version->getDefaultBranch()) {
                $versionCode = $version->getVersion();
            }
        }
        $package->version = $versionCode;
        if (!$versionCode) {
            return; // OnCompleted should check for no version and transition to a new state
            dd($packagistPackage);
        }
        assert($versionCode, "No default version?");

        $lastUpdated = $version->getTime();
        if (str_contains($packageName, 'media')) {
//            dd($packagistPackage, $lastUpdated);
        }
        $package->setLastUpdatedOnPackagist($lastUpdated);
        $package->version = $versionCode; // could also be an array of the version data.
        // @todo: skip if no new data

        $package->lastUpdated = new \DateTimeImmutable(); // now

        /**
         * @var PackagistPackage\Version $version
        */
//            foreach ($packagistPackage->getVersions() as $versionCode => $version) {
                // need a different API call for github stars.
                //                if ($package->getFavers() || $package->getGithubStars()) {
                //                    dd($package->getFavers(), $package);
                //                }
                //                dd($composer, $package);
                //                $package->getDescription(); //
                //                assert($package->getDescription() == $version->getDescription(), $package->getDescription() . '<>' . $version->getDescription());
                $json = $this->serializer->serialize($version, 'json');

                $package->stars = $packagistPackage->getFavers();
                $package->downloads = $packagistPackage->getDownloads()->getTotal();
                $package->description = $version->getDescription();
                $package->data = json_decode($json, true);
    }

    #[AsMessageHandler]
    public function handleFetchComposer(FetchComposer $message): void
    {
        $package = $this->packageRepository->findOneBy(['name' => $message->getName()]);
        assert($package);
        // @todo: check update time or use a real cache.
        if (!$package->packagistData) {
            $packagistInfoUrl = sprintf('https://packagist.org/packages/%s.json', $message->getName());
            $info = json_decode(file_get_contents($packagistInfoUrl), true);
            $package->packagistData = $info['package'];
        }
        if ($message->getType() === 'composer') {
            $this->packageService->populateFromComposerData($package);
        }
        $this->packageService->addPackage($package);
        //        dd($package->getPhpVersions(), $package->getPhpVersionString());
        $this->entityManager->flush();
        //        dd($package);
    }
}
