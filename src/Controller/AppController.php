<?php

namespace App\Controller;

use App\Repository\EndpointRepository;
use cebe\openapi\Reader;
use Meilisearch\Client;
use Meilisearch\Meilisearch;
use Survos\MeiliAdminBundle\Service\MeiliService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AppController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(MEILI_SERVER)%')] private string $meiliServer,
        #[Autowire('%env(MEILI_SEARCH_KEY)%')] private string $apiKey,
        private MeiliService $meiliService,
        private UrlGeneratorInterface $router,
        private EndpointRepository $endpointRepository,
    ) {}

    #[Route('/template/{indexName}', name: 'app_template')]
//    #[Template('app/insta.html.twig')]
    public function jsTemplate(string $indexName): Response|array
    {
        $jsTwigTemplate = __DIR__ . '/../../templates/js/' . $indexName . '.html.twig';
        assert(file_exists($jsTwigTemplate), "missing $jsTwigTemplate");
        $template = file_get_contents($jsTwigTemplate);
        return new Response($template);
    }

    #[Route('/', name: 'app_homepage')]
    #[Template('app/homepage.html.twig')]
    public function home(): Response|array
    {

        return [
            'endpoints' => $this->endpointRepository->findAll(),
        ];
    }

    #[Route('/index/{indexName}', name: 'app_insta')]
    #[Template('app/insta.html.twig')]
    public function index(
        string $indexName, //  = 'packagesPackage',
        #[MapQueryParameter] bool $useProxy = false
    ): Response|array
    {
        $endpoint = $this->endpointRepository->findOneBy([
            'name' => $indexName]
        );

        if (0) {
            $dummyServer = 'https://dummy.survos.com/api/docs.jsonopenapi';
// realpath is needed for resolving references with relative Paths or URLs
            $openapi = Reader::readFromJsonFile($dummyServer);
            $openapi->resolveReferences();
        }

        // Entity, then _list_ of groups separated by _
//        dd($openapi->components->schemas['Product.jsonld-product.read_product.details']);


//        dd($openapi);

        $locale = 'en'; // @todo
        $index = $this->meiliService->getIndexEndpoint($indexName);
        $settings = $index->getSettings();
        $sorting[] = ['value' => $indexName, 'label' => 'relevancy'];
        foreach ($settings['sortableAttributes'] as $sortableAttribute) {
            foreach (['asc','desc'] as $direction) {
                $sorting[] = [
                    'label' => sprintf("%s %s", $sortableAttribute, $direction),
                    'value' => sprintf("%s:%s:%s", $indexName, $sortableAttribute, $direction)
                    ];
            }
        }


        $facets = $settings['filterableAttributes'];

        // this is specific to our way of handling related, translated messages
        $related = $this->meiliService->getRelated($facets, $indexName, $locale);
        // use proxy for translations or hidden
        $params = [
            'server' =>
                $useProxy
                    ? $this->router->generate('meili_proxy', [],
                    UrlGeneratorInterface::ABSOLUTE_URL)
                    : $this->meiliServer,

            'apiKey' => $this->apiKey,
            'indexName' => $indexName,
            'facets' => $facets,
            'sorting' => $sorting,
            'settings' => $settings,
            'endpoint' => $endpoint,
            'related' => $related, // the facet lookups
        ];
        return $params;
    }


}
