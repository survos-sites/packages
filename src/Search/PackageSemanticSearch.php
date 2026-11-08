<?php

declare(strict_types=1);
namespace App\Search;

use App\Entity\Package;
use App\Service\PackageEmbeddingService;
use Survos\SearchBundle\Attribute\AsSearch;
use Survos\SearchBundle\Search\AbstractFieldSearch;
use Survos\SearchBundle\Search\HitTemplateSearchInterface;
use Survos\SearchBundle\Twig\Components\Facet\RefinementList;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

#[AsSearch(index: Package::class, name: 'package_semantic', adapter: 'es')]
final class PackageSemanticSearch extends AbstractFieldSearch implements HitTemplateSearchInterface
{
    public function __construct(private PackageEmbeddingService $embeddings, private PropertyAccessorInterface $accessor) {}
    protected function getFieldClass(array $options = []): string { return Package::class; }
    public function getHitTemplate(): ?string { return 'search/semantic_hit.html.twig'; }
    public function build(array $options = []): void
    {
        $this->setAdapterParameters([]);
        parent::build($options);
        $p = $this->getAdapterParameters();
        $p['index'] = 'package_semantic';
        $p['baseFilters'] = [['term' => ['state.keyword' => 'documented']], ['exists' => ['field' => 'embedding']]];
        $p['searchFields'] = ['name^3', 'description^2', 'readme'];
        foreach (['phpVersions', 'symfonyVersions'] as $field) {
            $p['mappings'][$field] = ['type' => 'keyword'];
            $p['facetFields'][$field] = $field;
            $this->addFacet($field, $field === 'phpVersions' ? 'PHP' : 'Symfony', RefinementList::class);
        }
        $p['sourceFields'] = ['id', 'name', 'description', 'vendor', 'phpVersions', 'symfonyVersions', 'state', 'readme'];
        unset($p['mappings']['embeddingFingerprint']);
        $p['mappings']['embedding'] = ['type' => 'dense_vector', 'dims' => PackageEmbeddingService::DIMENSIONS, 'index' => true, 'similarity' => 'cosine'];
        $p['vectorField'] = 'embedding';
        $p['vectorDimensions'] = PackageEmbeddingService::DIMENSIONS;
        $p['vectorSimilarity'] = 'cosine';
        $p['retrievalMode'] = 'lexical';
        $p['minimumSimilarity'] = 0.35;
        $p['embeddingProvider'] = $this->embeddings;
        $fields = $p['sourceFields'];
        $p['documentMapper'] = function (Package $package) use ($fields): array {
            $document = [];
            foreach ($fields as $field) { $document[$field] = $this->accessor->getValue($package, $field); }
            $document['embedding'] = $this->embeddings->cachedVector($package);
            return $document;
        };
        $this->setAdapterParameters($p);
        $this->addAvailableSort(null, 'Relevance');
    }
}
