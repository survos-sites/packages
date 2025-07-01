<?php

namespace App\Entity;

use App\Repository\EndpointRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EndpointRepository::class)]
class Endpoint
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue]
        #[ORM\Column]
        private(set) ?int $id = null,

        #[ORM\Column]
        private(set) ?string $name = null,

        #[ORM\Column]
        public ?string $label = null,

        #[ORM\Column]
        public int $columns = 3,

        private(set) ?string $code = null,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        public ?array $settings = null,

    )
    {
    }


    public function getId(): ?int
    {
        return $this->id;
    }
}
