<?php

// uses Survos Param Converter, from the UniqueIdentifiers method of the entity.

namespace App\Controller;

use App\Entity\Package;
use App\Repository\PackageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Survos\WorkflowBundle\Traits\HandleTransitionsTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/')]
class PackageCollectionController extends AbstractController
{
    use HandleTransitionsTrait;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Route(path: '/{style}', name: 'app_homepage', methods: ['GET'])]
    public function browse(Request $request,
        string $style = 'simple', //  'normal'
    ): Response {
        // WorkflowInterface $projectStateMachine
        $markingData = []; // $this->workflowHelperService->getMarkingData($projectStateMachine, $class);
        $apiRoute = $request->get('doctrine', false) ? 'doctrine-packages' : 'meili-packages';

        return $this->render('package/browse.html.twig', [
            'packageClass' => Package::class,
            'style' => $style,
            'apiRoute' => $apiRoute,
            'filter' => [],

            //            'owner' => $owner,
        ]);
    }

    #[Route('/index', name: 'package_index')]
    public function index(PackageRepository $packageRepository): Response
    {
        return $this->render('package/index.html.twig', [
            'packages' => $packageRepository->findBy([], [], 30),
        ]);
    }

    #[Route('package/new', name: 'package_new')]
    public function new(Request $request): Response
    {
        $package = new Package();
        $form = $this->createForm(PackageType::class, $package);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->entityManager;
            $entityManager->persist($package);
            $entityManager->flush();

            return $this->redirectToRoute('package_index');
        }

        return $this->render('package/new.html.twig', [
            'package' => $package,
            'form' => $form->createView(),
        ]);
    }
}
