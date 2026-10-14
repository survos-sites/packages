<?php

// uses Survos Param Converter, from the UniqueIdentifiers method of the entity.

namespace App\Controller;

use App\Entity\Package;
use App\Schema\PackageSchema;
use App\Repository\PackageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/packages')]
class PackageCollectionController extends AbstractController
{

    public function __construct(
    )
    {
    }

    #[Route(path: '/{style}', name: 'package_browse', methods: [Request::METHOD_GET], requirements: ['style' => 'normal|simple'])]
    public function browse(
        string $style = 'normal', //  'simple', //  'normal'
    ): Response {

        return $this->render('package/browse.html.twig', [
            'packageClass' => Package::class,
            'style' => $style,
            'apiGetCollectionUrl' => $this->generateUrl('doctrine-packages'),
            'filter' => [],

            //            'owner' => $owner,
        ]);
    }

    #[Route('/index', name: 'package_index', methods: [Request::METHOD_GET])]
    public function index(
        PackageRepository $packageRepository,
        Request $request,
        PackageSchema $packageSchema,
    ): Response {
        $packages = $packageRepository->findBy([], [], 30);

        // CollectionPage -> ItemList -> ListItem per package, each pointing at that
        // package's own SoftwareSourceCode node in this same graph.
        $packageSchema->addPackageList(
            packages: $packages,
            siteUrl: $request->getSchemeAndHttpHost(),
            canonicalUrl: $request->getUri(),
            name: 'Symfony bundles',
        );

        return $this->render('package/index.html.twig', [
            'packages' => $packages,
        ]);
    }

}
