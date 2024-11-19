<?php

namespace App\Entity;

use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Repository\PackageRepository;
use App\Workflow\BundleWorkflow;
use App\Workflow\BundleWorkflowInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\ApiGrid\Api\Filter\FacetsFieldSearchFilter;
use Survos\ApiGrid\State\MeiliSearchStateProvider;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\ApiGrid\Api\Filter\MultiFieldSearchFilter;
use Survos\WorkflowBundle\Attribute\Transition;
use Survos\WorkflowBundle\Traits\MarkingInterface;
use Survos\WorkflowBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: PackageRepository::class)]
#[ApiResource(
    operations: [new Get(), new GetCollection(
        name: 'doctrine-packages'
    )],
    normalizationContext: ['groups' => ['package.read', 'marking', 'browse', 'transitions', 'rp']],
    denormalizationContext: ['groups' => ["Default", "minimum", "browse"]],
)]
#[GetCollection(
    name: 'meili-packages',
    uriTemplate: "meili/packages",
    provider: MeiliSearchStateProvider::class,
    normalizationContext: [
        'groups' => ['package.read', 'browse', 'tree','marking'],
    ]
)]

#[ApiFilter(OrderFilter::class, properties: ['marking','vendor', 'name','stars'], arguments: ['orderParameterName' => 'order'])]
#[ApiFilter(SearchFilter::class, properties: ["marking" => "exact", 'name' => 'partial'])]
#[ApiFilter(FacetsFieldSearchFilter::class, properties: ['vendor','symfonyVersions', 'phpVersions', 'keywords', 'marking'])]
#[ApiFilter(
    MultiFieldSearchFilter::class,
    properties: ['name', 'description'],
    arguments: ["searchParameterName" => "search"]
)]
//#[Groups(['package.read'])] // NO! The embedded json data is too big
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
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\Column(type: 'integer')]
    #[Groups(['browse'])]
    private $id;
    #[ORM\Column(type: 'string', length: 255)]
    #[Groups(['browse'])]
    private $name;

    #[ORM\Column(nullable: true)]
    private ?array $data = null;

    #[ORM\Column(length: 255)]
    #[Groups('package.read')]
    private ?string $vendor = null;

    #[ORM\Column(type: Types::TEXT, length: 255, nullable: true)]
    #[Groups(['package.read'])]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $version = null;

    #[ORM\Column(length: 255)]
    #[Groups(['package.read'])]
    private ?string $shortName = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['package.read'])]
    private ?array $symfonyVersions = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['package.read'])]
    private ?array $keywords = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['package.read'])]
    private ?int $stars = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['package.read'])]
    private ?array $phpVersions = null;

    #[ORM\Column(type: Types::TIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastModifiedTime = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $phpVersionString = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['package.read'])]
    private ?string $repo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $replacement = null;

    public function __construct()
    {
        $this->marking = self::PLACE_NEW;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getData(): array|object|null
    {
        return $this->data;
    }

    public function setData(array|object|null $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getVendor(): ?string
    {
        return $this->vendor;
    }

    public function setVendor(string $vendor): static
    {
        $this->vendor = $vendor;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function getShortName(): ?string
    {
        return $this->shortName;
    }

    public function setShortName(string $shortName): static
    {
        $this->shortName = $shortName;

        return $this;
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

    public function addPhpVersion(string $version): self
    {
        $versions = $this->getPhpVersions();
        if (!in_array($version, $versions)) {
            $versions[] = $version;
            $this->setPhpVersions(array_unique($versions));
        }
        return $this;
    }

    #[Groups(['package.read'])]
    public function getKeywords(): array
    {
        return $this->keywords;
    }

    public function setKeywords(?array $keywords): static
    {
        $this->keywords = $keywords;

        return $this;
    }

    public function getStars(): ?int
    {
        return $this->stars;
    }

    public function setStars(?int $stars): static
    {
        $this->stars = $stars;

        return $this;
    }

    public function getPhpVersions(): ?array
    {
        return $this->phpVersions;
    }

    public function setPhpVersions(?array $phpVersions): static
    {
        $this->phpVersions = $phpVersions;

        return $this;
    }

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
        return $this->getName();
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
}
