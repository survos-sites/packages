<?php
// src/Controller/MeiliProxyController.php
namespace App\Controller;

use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Survos\MeiliAdminBundle\Service\MeiliService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class MeiliProxyController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $client,
        private CacheInterface      $cache,
        private LoggerInterface $logger,
        private MeiliService $meiliService,
        #[Autowire('%env(MEILI_SERVER)%')]
        private string              $meiliBaseUri,
        #[Autowire('%env(MEILI_SEARCH_KEY)%')]
        private string              $defaultApiKey,
    ) {
        // strip any trailing slash
        $this->meiliBaseUri = rtrim($this->meiliBaseUri, '/');
    }

    #[Route(
        '/meili/{path}',
        name: 'meili_proxy',
        requirements: ['path' => '.+'],
        methods: ['GET','POST','PUT','DELETE','PATCH']
    )]
    public function proxy(Request $request, string $path='/'): StreamedResponse|Response
    {
        $method = $request->getMethod();
        if (str_ends_with($path, 'facet-search')) {
            $data = json_decode($request->getContent(), true);
            $q = $data['facetQuery'];
            // @todo: get facet distribution instead.
            $related = $this->meiliService->getRelated($data['facets'], 'm_px_victoria_obj', 'en' );
            if (array_key_exists($data['facetName'], $related)) {
                $x=[];
                foreach ($related[$data['facetName']] as $facet) {
                    if (str_contains($facet, $q)) {
                        $x[] = [
                            'value' => $facet,
                            'count' => 5,
                        ];
                    }
                }
                return $this->json([
                    'processingTimeMs' => 3,
                    'facetQuery' => $data['facetQuery'],
                    'facetHits' => $x,
                ]);
                $this->logger->warning(json_encode($request->request->all()));
            }
            // make a call to search the related table labels!
            // since meili doesn't do partial word searches, we have to go to the
            // database for this, but return the same formatted response :-(
            // OR we get it from the javascript, since we have the map already.

//            $method = 'POST';
        }
        // this is the actual server, e.g. ms.survos.com
        $uri    = "{$this->meiliBaseUri}/{$path}";

        // prefer incoming Auth header, else fall back
        $incoming = $request->headers->get('Authorization');
//        $auth     = $incoming ?: "Bearer {$this->defaultApiKey}";
        $auth     = "Bearer {$this->defaultApiKey}";

        // rebuild headers
        $headers = $request->headers->all();
        unset($headers['host']);
//        $headers = [];
        $headers['Authorization'] = $auth;
//        dd($headers, $uri);
//        $headers['Accept-Encoding'] = 'identity';

        if ($method === 'GET') {
//            return $this->fetchAndCache($uri, $request, $auth, $headers);
        }

        // non-GET: just proxy & stream
//        dump($headers);
        $response = $this->client->request($method, $uri, [
            'body'    => $request->getContent(),
            'query'   => $request->query->all(),
//            'headers' => [
//                'Authorization' => 'Bearer '.$auth
//            ],
            'headers' => $headers,
        ]);
        if ($response->getStatusCode() == 200) {
//            dump($response->getContent());
        } else {
            dd($headers, $response, $response->getStatusCode());
        }
        $this->logger->error(json_encode($headers['Authorization']));
//        dd($headers, $response->getStatusCode(), $method, $uri);
//        dd($response, $response->getContent());

        $meiliHeaders = $response->getHeaders(false);
        // Grab all raw headers from MeiliSearch
        $contentType  = $meiliHeaders['content-type'][0] ?? 'application/json';

        $stream = new StreamedResponse(function () use ($response) {
            foreach ($this->client->stream($response) as $chunk) {
                $this->logger->warning(json_encode($chunk));
                if ($chunk->isTimeout()) {
                    continue;
                }
                echo $chunk->getContent();
                flush();
            }
        });
        $stream->headers->set('Content-Type', $contentType);

        if (!empty($meiliHeaders['content-encoding'][0])) {
            $stream->headers->set(
                'Content-Encoding',
                $meiliHeaders['content-encoding'][0]
            );
        }

        if (!empty($meiliHeaders['x-meilisearch-request-id'][0])) {
            $stream->headers->set(
                'X-MeiliSearch-Request-Id',
                $meiliHeaders['x-meilisearch-request-id'][0]
            );
        }

        return $stream;
    }

    /**
     * @param string $uri
     * @param Request $request
     * @param string $auth
     * @param array $headers
     * @return StreamedResponse
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function fetchAndCache(string $uri, Request $request, string $auth, array $headers): StreamedResponse
    {
        $cacheKey = 'meili_' . md5($uri . serialize($request->query->all()) . $auth);

        $json = $this->cache->get($cacheKey, function (ItemInterface $item) use ($uri, $request, $headers) {
            $item->expiresAfter(3600);
            $response = $this->client->request('GET', $uri, [
//                    'decompress' => true,
                'query' => $request->query->all(),
                'headers' => $headers,
            ]);

            if ($response->getStatusCode() !== 200) {
                dd($response->getStatusCode(), $headers);
            }
            $rawResponse = $response->getContent();
            dd($response->getContent(), $response->toArray(), $response->getStatusCode(), $response->getInfo(), $headers, $response);
            return $response->getContent();
        });

        return new StreamedResponse(fn() => print($json), 200, [
            'Content-Type' => 'application/json',
        ]);
    }
}
