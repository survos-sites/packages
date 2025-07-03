<?php

namespace App\Command;

use App\Entity\Endpoint;
use App\Repository\EndpointRepository;
use Doctrine\ORM\EntityManagerInterface;
use Survos\MeiliAdminBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:meili', 'Import meili indices into local table')]
class MeiliCommand
{
	public function __construct(
        private EntityManagerInterface $entityManager,
        private EndpointRepository $endpointRepository,
        private MeiliService $meiliService,
    )
	{
	}


	public function __invoke(SymfonyStyle $io): int
	{
        //        $indexes = $this->meiliService->getMeiliClient()->getIndexes(); dd($indexes);

        foreach ([

            'packages_Package' => 'bundles',
                     'dtdemo_Official' => "congress",
//                     'kpa_Song',
                     'kpa_Video' => 'videos',
                     'm_px_aust_obj' => 'AustObj',
                     'm_px_aust_mat' => 'AustMat',
                     'm_px_victoria_obj' => 'Victoria',
                     'm_Owner' => 'museums',
                     'm_px_cleveland_obj' => 'CMA',
                     'm_px_met_obj' => 'MET',
                     'sais_Media' => 'Sais/Media',
                     'dtdemo_Instrument' => 'Instruments',
                     'dtdemo_Work' => 'Songs',
                     'dtdemo_Jeopardy' => 'Jeopardy',
                     'dummy_Product' => 'products'
                 ] as $indexName => $label) {
            try {
                $index = $this->meiliService->getIndex($indexName);
            } catch (\Exception $e) {
                continue;
            }
            if (!$endpoint = $this->endpointRepository->findOneBy(['name' => $indexName])) {
                $endpoint = new Endpoint(
                    name: $indexName,
                );
                $this->entityManager->persist($endpoint);
            }
            $endpoint->settings = $index->getSettings();
            $endpoint->label = $label;
            if (in_array($indexName, ['dtdemo_Instrument', 'dtdemo_Jeopardy'])) {
                $columns = 4;
            } elseif (in_array($indexName, ['kpa_Video', 'dtdemo_Official'])) {
                $columns = 3;
            } else {
                $columns = 2;
            }
            $endpoint->columns = $columns;
        }
        $this->entityManager->flush();


        $io->success(self::class . " success.");
		return Command::SUCCESS;
	}
}
