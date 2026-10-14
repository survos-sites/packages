<?php

namespace App\Dto;

use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\DtoProcessor;
use App\State\DtoProvider;

#[ApiResource(
    provider: DtoProvider::class,
    processor: DtoProcessor::class,
    operations: [
        new GetCollection(parameters: [
            'name' => new QueryParameter(schema: ['type' => 'string'], description: 'Filter by name (partial match)'),
            'marking' => new QueryParameter(schema: ['type' => 'string']),
        ]),
        new Get()
    ]
)]
class PackageDto {
    #[ApiProperty('identifier', identifier: true)]
    public int $id;

    #[ApiProperty('The name of the package')]
    public string $name;

    #[ApiProperty('Workflow marking')]
    public ?string $marking;

    #[ApiProperty('The number of times this test has been run.')]
    public int $count=0;

}
