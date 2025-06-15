<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\PackageRepository;
use App\Workflow\BundleWorkflow;
use App\Workflow\BundleWorkflowInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\ApiGrid\Api\Filter\FacetsFieldSearchFilter;
use Survos\ApiGrid\Api\Filter\MultiFieldSearchFilter;
use Survos\ApiGrid\State\MeiliSearchStateProvider;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\WorkflowBundle\Traits\MarkingInterface;
use Survos\WorkflowBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: PackageRepository::class)]
#[ApiResource(
    operations: [new Get(), new GetCollection(
        name: 'doctrine-packages'
    )],
    normalizationContext: ['groups' => ['package.read', 'marking', 'browse', 'transitions', 'rp']],
    denormalizationContext: ['groups' => ['Default', 'minimum', 'browse']],
)]
#[GetCollection(
    name: 'meili-packages',
    uriTemplate: 'meili/packages',
    provider: MeiliSearchStateProvider::class,
    normalizationContext: [
        'groups' => ['package.read', 'package.facets', 'browse', 'tree', 'marking'],
    ]
)]
#[ApiFilter(OrderFilter::class, properties: ['marking', 'vendor', 'name', 'stars', 'favers', 'downloads'], arguments: ['orderParameterName' => 'order'])]
#[ApiFilter(SearchFilter::class, properties: ['marking' => 'exact', 'name' => 'partial'])]
#[ApiFilter(FacetsFieldSearchFilter::class, properties: ['vendor', 'symfonyVersions', 'phpUnitVersion', 'phpVersions', 'sourceType', 'keywords', 'marking'])]
#[ApiFilter(
    MultiFieldSearchFilter::class,
    properties: ['name', 'description'],
    arguments: ['searchParameterName' => 'search']
)]
// #[Groups(['package.read'])] // NO! The embedded json data is too big
class Package implements RouteParametersInterface, MarkingInterface, BundleWorkflowInterface, \Stringable
{
    use RouteParametersTrait;
    use MarkingTrait;

    public const array UNIQUE_PARAMETERS = ['packageId' => 'id'];
    //    #[Groups(['rp'])]
    //    public function getUniqueIdentifiers(): array
    //    {
    //        return ['packageId' => $this->getId()];
    //    }

    #[ORM\Id]
    #[ORM\GeneratedValue()]
    #[ORM\Column(type: 'integer')]
    #[Groups(['browse'])]
    private int $id;

    #[ORM\Column(nullable: true)]
    public ?array $data = null;

    #[ORM\Column(length: 255)]
    #[Groups('package.read')]
    private(set) ?string $vendor = null;

    #[ORM\Column(type: Types::TEXT, length: 255, nullable: true)]
    #[Groups(['package.read'])]
    public ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $version = null;

    #[ORM\Column(length: 255)]
    #[Groups(['package.read'])]
    private(set) ?string $shortName = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['package.facets', 'package.read'])]
    private ?array $symfonyVersions = null;

//    #[ORM\Column(nullable: true)]
//    private ?array $keywords = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['package.read'])]
    public ?int $stars = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['package.read'])]
    public ?array $phpVersions = null;

    #[ORM\Column(type: Types::TIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastModifiedTime = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['package.read'])]
    public ?string $phpVersionString = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['package.read'])]
    public ?string $repo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $replacement = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['package.read'])]
    private ?string $phpUnitVersionString = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['package.read'])]
    private ?array $phpUnitVersions = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['package.read'])]
    private ?string $symfonyVersionString = null;

    #[ORM\Column(nullable: true)]
    private ?array $packagistData = null;

    #[ORM\Column(length: 8, nullable: true)]
    #[Groups('package.read')]
    public ?string $sourceType = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups('package.read')]
    public ?string $sourceUrl = null;

    #[ORM\Column(nullable: true)]
    /** $downloads Stored in the database */
    public ?int $downloads = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        #[Groups(['browse'])]
        private(set) readonly ?string $name=null
    )
    {
        [$this->vendor, $this->shortName] = explode('/', $this->name);
        $this->marking = self::PLACE_NEW;
    }

    public function getId(): ?int
    {
        return $this->id;
    }


    public function getSymfonyVersions(): array
    {
        return $this->symfonyVersions ?? [];
    }

    public function setSymfonyVersions(?array $symfonyVersions): static
    {
        $this->symfonyVersions = $symfonyVersions;

        return $this;
    }

    public function addSymfonyVersion(string $version): self
    {
        $versions = $this->getSymfonyVersions();
        if (!in_array($version, $versions)) {
            $versions[] = $version;
            $this->setSymfonyVersions(array_unique($versions));
        }

        return $this;
    }

    public function addPhpUnitVersion(string $version): self
    {
        $versions = $this->getPhpUnitVersions() ?? [];
        if (!in_array($version, $versions)) {
            $versions[] = $version;
            $this->setPhpUnitVersions(array_unique($versions));
        }

        return $this;
    }

//    public function addPhpVersion(string $version): self
//    {
//        $versions = $this->getPhpVersions();
//        if (!in_array($version, $versions)) {
//            $versions[] = $version;
//            $this->setPhpVersions(array_unique($versions));
//        }
//
//        return $this;
//    }

    #[Groups(['package.facets', 'package.read'])]
    public function getKeywords(): array
    {
        return $this->data['keywords'] ?? [];
    }

//    public function setKeywords(?array $keywords): static
//    {
//        $this->keywords = $keywords;
//
//        return $this;
//    }

//    public function getStars(): ?int
//    {
//        return $this->stars;
//    }
//
//    public function setStars(?int $stars): static
//    {
//        $this->stars = $stars;
//
//        return $this;
//    }

    public function getLastModifiedTime(): ?\DateTimeInterface
    {
        return $this->lastModifiedTime;
    }

    public function setLastModifiedTime(?\DateTimeInterface $lastModifiedTime): static
    {
        $this->lastModifiedTime = $lastModifiedTime;

        return $this;
    }

    public function getFlowCode(): string
    {
        return BundleWorkflow::WORKFLOW_NAME;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getPhpVersionString(): ?string
    {
        return $this->phpVersionString;
    }

    public function setPhpVersionString(?string $phpVersionString): static
    {
        $this->phpVersionString = $phpVersionString;

        return $this;
    }

    public function getRepo(): ?string
    {
        return $this->repo;
    }

    public function setRepo(?string $repo): static
    {
        $this->repo = $repo;

        return $this;
    }

    public function getReplacement(): ?string
    {
        return $this->replacement;
    }

    public function setReplacement(?string $replacement): static
    {
        $this->replacement = $replacement;

        return $this;
    }

    public function getPhpUnitVersionString(): ?string
    {
        return $this->phpUnitVersionString;
    }

    public function setPhpUnitVersionString(?string $phpUnitVersionString): static
    {
        $this->phpUnitVersionString = $phpUnitVersionString;

        return $this;
    }

    public function getPhpUnitVersions(): ?array
    {
        return $this->phpUnitVersions;
    }

    public function setPhpUnitVersions(?array $phpUnitVersions): static
    {
        $this->phpUnitVersions = $phpUnitVersions;

        return $this;
    }

    public function getSymfonyVersionString(): ?string
    {
        return $this->symfonyVersionString;
    }

    public function setSymfonyVersionString(?string $symfonyVersionString): static
    {
        $this->symfonyVersionString = $symfonyVersionString;

        return $this;
    }

    public function getPackagistData(): ?array
    {
        return $this->packagistData;
    }

    public function setPackagistData(?array $packagistData): static
    {
        $this->packagistData = $packagistData;

        return $this;
    }

    #[Groups(['package.read'])]
    /**
     *   We get this right from the metadata rather than storing it.  We can sort it in meili though.
     */
    public function getFavers(): ?int
    {
        return $this->getPackagistData()['favers'] ?? null;
    }

    #[Groups(['package.read'])]
    public function getDownloads(): ?int
    {
        return $this->downloads;
    }

    public function hasValidSymfonyVersion(): bool
    {
        return count($this->getSymfonyVersions()) > 0;
    }

    public function hasValidPhpVersion(): bool
    {
        return count($this->phpVersions) > 0;
    }

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function setSourceType(string $sourceType): static
    {
        $this->sourceType = $sourceType;

        return $this;
    }


}
