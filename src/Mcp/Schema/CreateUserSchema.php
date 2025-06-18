<?php

namespace App\Mcp\Schema;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(required: ['emailAddress', 'username'])]
class CreateUserSchema
{
    #[Assert\Email]
    #[Assert\NotBlank]
    #[Assert\Length(min: 5, max: 255)]
    #[OA\Property(description: 'The email address of the user', type: 'string', maxLength: 255, minLength: 5, nullable: false)]
    public string $emailAddress;

    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 50)]
    #[OA\Property(description: 'The username of the user', type: 'string', maxLength: 50, minLength: 3, nullable: false)]
    public string $username;
}
