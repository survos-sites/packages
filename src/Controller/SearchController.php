<?php

declare(strict_types=1);

namespace App\Controller;

use Survos\SearchBundle\Search\SearchProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Browser search over the Elasticsearch package index, via search-bundle's /instant-search. */
final class SearchController extends AbstractController
{
    private const string SEARCH = 'app_package';

    private const array FACETS = [
        'symfonyVersions' => 'Symfony',
        'phpVersions' => 'PHP',
        'marking' => 'Status',
        'vendor' => 'Vendor',
        'sourceType' => 'Source',
    ];

    #[Route('/', name: 'app_homepage', methods: ['GET'])]
    public function index(
        SearchProvider $provider,
        HttpClientInterface $http,
        #[Autowire(env: 'ELASTICSEARCH_DSN')] string $dsn,
        #[Autowire(env: 'SEARCH_INDEX_PREFIX')] string $prefix,
    ): Response {
        $search = $provider->getSearch(self::SEARCH)->create();
        $available = array_map(static fn ($facet) => $facet->getProperty(), $search->getFacets());
        $sorts = array_map(static fn ($sort) => ['value' => self::SEARCH.'::'.$sort->getKey(), 'label' => $sort->getLabel()], $search->getAvailableSorts());

        return $this->render('search/index.html.twig', [
            'name' => self::SEARCH,
            'facets' => array_intersect_key(self::FACETS, array_flip($available)),
            'sorts' => $sorts,
            'indexProblem' => $this->indexProblem($http, $dsn, $prefix.'package'),
        ]);
    }

    #[Route('/search-template/package', name: 'app_search_template', methods: ['GET'])]
    public function template(): Response
    {
        // Twig source, rendered per hit in the browser by twig-browser.
        return new Response(
            file_get_contents($this->getParameter('kernel.project_dir').'/templates/search/package.browser.twig'),
            200,
            ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=300'],
        );
    }

    /** Why search cannot work (null when the index is there), so the page can say what to run. */
    private function indexProblem(HttpClientInterface $http, string $dsn, string $alias): ?string
    {
        $base = preg_replace('#^elasticsearch://#', 'http://', $dsn);
        try {
            $status = $http->request('HEAD', rtrim($base, '/').'/'.$alias, ['timeout' => 2])->getStatusCode();
        } catch (\Throwable) {
            return 'unreachable';
        }

        return 404 === $status ? 'missing' : null;
    }
}
