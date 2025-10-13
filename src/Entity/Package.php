<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\PackageRepository;
use App\Workflow\BundleWorkflow;
use App\Workflow\BundleWorkflowInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\MeiliBundle\Api\Filter\FacetsFieldSearchFilter;

use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: PackageRepository::class)]
#[ApiResource(
    operations: [new Get(), new GetCollection(
        name: 'doctrine-packages'
    )],
    normalizationContext: ['groups' => ['package.read', 'marking', 'browse', 'transitions', 'rp']],
    denormalizationContext: ['groups' => ['Default', 'minimum', 'browse']],
)]

//#[GetCollection(
//    name: 'meili-packages',
//    uriTemplate: 'meili/packages',
//    provider: MeiliSearchStateProvider::class,
//    normalizationContext: [
//        'groups' => ['package.read', 'package.facets', 'browse', 'tree', 'marking'],
//    ]
//)]
#[ApiFilter(OrderFilter::class, properties: self::SORTABLE, arguments: ['orderParameterName' => 'order'])]
#[ApiFilter(SearchFilter::class, properties: [
    'marking' => 'exact', // api platform, it's a facet in meili
    'name' => 'partial',
    'description' => 'partial',
])]

//#[ApiFilter(FacetsFieldSearchFilter::class, properties:

#[MeiliIndex(
    sortable: self::SORTABLE,
    searchable: ['marking','name','description'],
    filterable: ['vendor', 'symfonyVersions', 'phpUnitVersion', 'phpVersions', 'stars', 'keywords', 'marking']
)]
//#[ApiFilter(
//    MultiFieldSearchFilter::class,
//    properties: ['name', 'description'],
//    arguments: ['searchParameterName' => 'search']
//)]
// #[Groups(['package.read'])] // NO! The embedded json data is too big
class Package implements RouteParametersInterface, MarkingInterface, \Stringable
{
    use RouteParametersTrait;
    use MarkingTrait;

    private const SORTABLE = [
        'marking', 'vendor', 'name', 'stars',
        'lastUpdatedOnPackagist',
        'favers', 'downloads'];


    public const array UNIQUE_PARAMETERS = ['packageId' => 'id'];
    //    #[Groups(['rp'])]
    //    public function getUniqueIdentifiers(): array
    //    {
    //        return ['packageId' => $this->getId()];
    //    }

    #[ORM\Id]
    #[ORM\Column()]
    #[Groups(['browse'])]
    private(set) string $id;

    #[ORM\Column(nullable: true)]
    public ?array $data = null;

    #[ORM\Column(length: 255)]
    #[Groups('package.read')]
    private(set) ?string $vendor = null;

    #[ORM\Column(type: Types::TEXT, length: 255, nullable: true)]
    #[Groups(['package.read'])]
    public ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['package.read'])]
    public ?string $version = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['package.read'])]
    public ?\DateTimeImmutable $lastUpdatedOnPackagist = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['package.read'])]
    public ?\DateTimeImmutable $lastUpdated = null;

    #[ORM\Column(length: 255)]
    #[Groups(['package.read'])]
    private(set) ?string $shortName = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['package.facets', 'package.read'])]
    public ?array $symfonyVersions = null;

    #[Groups(['package.read'])]
    #[ApiProperty("null if unknown (e.g. marking=new), other boolean")]
    public ?bool $hasValidSymfonyVersion {
        get => is_null($this->symfonyVersions) ? null : !empty($this->symfonyVersions);
    }
    #[Groups(['package.read'])]
    #[ApiProperty("null if unknown (e.g. marking=new), other boolean")]
    public ?bool $hasValidPhpVersion {
        get => is_null($this->phpVersions) ? null : !empty($this->phpVersions);
    }

    #[Groups(['package.facets', 'package.read'])]
    public array $keywords { get => $this->data['keywords'] ?? []; }

    #[ORM\Column(nullable: true, type: Types::INTEGER)]
    #[Groups(['package.read'])]
    public ?int $stars = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['package.read'])]
    public ?array $phpVersions = null;

    #[ORM\Column(type: Types::TIME_MUTABLE, nullable: true)]
    public ?\DateTimeInterface $lastModifiedTime = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['package.read'])]
    public ?string $phpVersionString = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['package.read'])]
    public ?string $repo = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $replacement = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['package.read'])]
    public ?string $phpUnitVersionString = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['package.read'])]
    public ?array $phpUnitVersions = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['package.read'])]
    public ?string $symfonyVersionString = null;

    #[ORM\Column(nullable: true)]
    public ?array $packagistData = null;

    #[ORM\Column(length: 8, nullable: true)]
    #[Groups('package.read')]
    public ?string $sourceType = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups('package.read')]
    public ?string $sourceUrl = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['package.read'])]
    /** $downloads now Stored in the database */
    public ?int $downloads = null;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 255)]
        #[Groups(['browse'])]
        private(set) readonly ?string $name=null
    )
    {
        [$this->vendor, $this->shortName] = explode('/', $this->name);
        $this->id = self::idFromName($this->name);
        $this->marking = BundleWorkflowInterface::PLACE_NEW;
    }

    public static function idFromName(string $name): string
    {
        $slug =  str_replace('/', '--', $name);
        $slug =  str_replace('.', '--', $slug);
        return $slug;
    }

    public function setLastUpdatedOnPackagist(string|\DateTimeInterface $timestamp): void
    {
        if (is_string($timestamp)) {
            $timestamp = new \DateTimeImmutable($timestamp);
        }
        $this->lastUpdatedOnPackagist = \DateTimeImmutable::createFromInterface($timestamp);
    }


    public function getSymfonyVersions(): array
    {
        return $this->symfonyVersions ?? [];
    }

    public function getFlowCode(): string
    {
        return BundleWorkflowInterface::WORKFLOW_NAME;
    }

    public function __toString(): string
    {
        return $this->name;
    }

}
