<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Where an external change feed was last read up to, e.g. Packagist's metadata/changes.json. */
#[ORM\Entity]
class FeedCursor
{
    public const string PACKAGIST = 'packagist';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 32)]
        public readonly string $id,
        // Packagist timestamps are in 1/10000 s and exceed 32 bits.
        #[ORM\Column(type: Types::BIGINT)]
        public string $position,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function advance(string|int $position): void
    {
        $this->position = (string) $position;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
