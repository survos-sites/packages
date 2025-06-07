<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AppController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(MEILI_SERVER)%')] private string $meiliServer,
        #[Autowire('%env(MEILI_API_KEY)%')] private string $apiKey,
    ) {}

    #[Route('/insta', name: 'app_insta')]
    public function index(): Response
    {
        return $this->render('app/insta.html.twig', [
            'server' => $this->meiliServer,
            'apiKey' => $this->apiKey
        ]);
    }
}
