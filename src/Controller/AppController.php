<?php

namespace App\Controller;

use Meilisearch\Client;
use Meilisearch\Meilisearch;
use Survos\MeiliAdminBundle\Service\MeiliService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AppController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(MEILI_SERVER)%')] private string $meiliServer,
        #[Autowire('%env(MEILI_SEARCH_KEY)%')] private string $apiKey,
        private MeiliService $meiliService,
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

    #[Route('/insta/{indexName}', name: 'app_insta')]
    #[Template('app/insta.html.twig')]
    public function index(string $indexName = 'packagesPackage'): Response|array
    {
        $locale = 'en'; // @todo
        $index = $this->meiliService->getIndexEndpoint($indexName);
        $settings = $index->getSettings();
        $facets = $settings['filterableAttributes'];
        // this is specific to our way of handling related, translated messages
        $related = $this->getRelated($facets, $indexName, $locale);
        $params = [
            'server' => $this->meiliServer,
            'apiKey' => $this->apiKey,
            'indexName' => $indexName,
            'facets' => $facets,
            'related' => $related, // the facet lookups
        ];
        return $params;
    }

    private function getRelated(array $facets, string $indexName, string $locale): array
    {
        $lookups = [];
        if (str_ends_with($indexName, '_obj'))
        {
            foreach ($facets as $facet) {
                if (!in_array($facet, ['type','cla','cat'])) {
                    continue;
                }
                $related = str_replace('_obj', '_' . $facet, $indexName);
                $index = $this->meiliService->getIndexEndpoint($related);
                $docs = $index->getDocuments();
                foreach ($docs as $doc) {
                    $lookups[$facet][$doc['id']] = $doc['t'][$locale]['label'];
                }
            }
        }
        return $lookups;

    }

    #[Route('/detail/{id}', name: 'app_detail')]
    #[Template('app/detail.html.twig')]
    public function details(string $id): Response|array
    {
        $client = new Client($this->meiliServer, $this->apiKey);

        $index = $client->getIndex('movies');
        return ['hit' => $index->getDocument($id)];

    }
}
