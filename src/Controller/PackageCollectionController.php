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

    public function __construct(
    )
    {
    }

    #[Route(path: '/api-grid/{style}', name: 'app_homepage', methods: [Request::METHOD_GET], requirements: ['style' => 'normal|simple'])]
    public function browse(Request $request,
        string $style = 'normal', //  'simple', //  'normal'
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

    #[Route('/index', name: 'package_index', methods: [Request::METHOD_GET])]
    public function index(PackageRepository $packageRepository): Response
    {
        return $this->render('package/index.html.twig', [
            'packages' => $packageRepository->findBy([], [], 30),
        ]);
    }

}
