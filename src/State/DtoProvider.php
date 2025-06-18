<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\PackageDto;

class DtoProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $dto = new PackageDto();
        $dto->name = 'test';
        // Return an array of MyCustomDto objects
        return [
            $dto
            // ...
        ];
    }
}
