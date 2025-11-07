<?php

namespace App\Dto;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Filter\DtoFilter;
use App\State\DtoProcessor;
use App\State\DtoProvider;

#[ApiResource(
    provider: DtoProvider::class,
    processor: DtoProcessor::class,
    operations: [
        new  GetCollection(),
//        new GetCollection(filters: [DtoFilter::class, 'test_dto.search_filter']),
        new Get()
    ]
)]
//#[ApiFilter(DtoFilter::class)]
//#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial', 'marking' => 'exact'])]
#[ApiFilter(DtoFilter::class, properties: ['name' => 'partial', 'marking' => 'exact'])]
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
