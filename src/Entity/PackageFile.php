<?php

declare(strict_types=1);

namespace App\Entity;

use App\Workflow\PackageFilePlace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One document of a package (its README or AGENTS.md), content-addressed: a changed
 * document is a new row, so an unchanged one is never processed again.
 */
#[ORM\Entity]
#[ORM\Table(name: 'package_file')]
#[ORM\Index(columns: ['package_id', 'kind'])]
class PackageFile
{
    public const string README = 'readme';
    public const string AGENTS = 'agents';
    public const array KINDS = [self::README, self::AGENTS];

    #[ORM\Id]
    #[ORM\Column]
    private(set) string $id;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $state = null;

    /** What the extract transition found: headings, shell commands, counts. */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $extract = null;

    #[ORM\Column(length: 64)]
    private(set) string $sha;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Package::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) readonly Package $package,
        #[ORM\Column(length: 16)]
        private(set) readonly string $kind,
        // The name the document was found under; null for rows created without a fetch.
        #[ORM\Column(length: 255, nullable: true)]
        private(set) readonly ?string $path,
        #[ORM\Column(type: Types::TEXT)]
        private(set) readonly string $content,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private(set) readonly \DateTimeImmutable $fetchedAt = new \DateTimeImmutable(),
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown package file kind "%s".', $kind));
        }
        $this->sha = hash('sha256', $content);
        $this->id = self::idFor($package, $kind, $this->sha);
    }

    public static function idFor(Package $package, string $kind, string $sha): string
    {
        return sprintf('%s--%s--%s', $package->id, $kind, substr($sha, 0, 12));
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(string $state, array $context = []): void
    {
        $this->state = PackageFilePlace::from($state)->value;
    }
}
