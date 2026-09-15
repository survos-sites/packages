<?php

declare(strict_types=1);

namespace App\Controller;

use Survos\SearchBundle\Search\SearchProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
    public function index(SearchProvider $provider): Response
    {
        $search = $provider->getSearch(self::SEARCH)->create();
        $available = array_map(static fn ($facet) => $facet->getProperty(), $search->getFacets());
        $sorts = array_map(static fn ($sort) => ['value' => self::SEARCH.'::'.$sort->getKey(), 'label' => $sort->getLabel()], $search->getAvailableSorts());

        return $this->render('search/index.html.twig', [
            'name' => self::SEARCH,
            'facets' => array_intersect_key(self::FACETS, array_flip($available)),
            'sorts' => $sorts,
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
}
