<?php

namespace App\Command;

use App\Entity\Endpoint;
use App\Repository\EndpointRepository;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Contracts\IndexesQuery;
use Survos\MeiliBundle\Service\MeiliService;
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


	public function __invoke(SymfonyStyle $io,
    #[Option] ?bool $reset = null
    ): int
	{

        if ($reset) {
            $this->endpointRepository->createQueryBuilder('endpoint')->delete()->getQuery()->execute();
        }
        foreach ($this->meiliService->getMeiliClient()->getIndexes(
            new IndexesQuery()->setLimit(500)
        ) as $index) {

//        }
//        if (0)
//        foreach ([
//                     'dtdemo_Instrument' => 'Instruments',
//
//            'packages_Package' => 'bundles',
//                     'dtdemo_Official' => "congress",
////                     'kpa_Song',
//                     'kpa_Video' => 'videos',
//                     'showcase_Project' => 'Sites',
//                     'showcase_Show' => 'Ciine',
//                     'm_px_aust_obj' => 'AustObj',
//                     'm_px_aust_mat' => 'AustMat',
//                     'm_px_victoria_obj' => 'Victoria',
//                     'm_Owner' => 'museums',
//                     'm_px_cleveland_obj' => 'CMA',
//                     'm_px_met_obj' => 'MET',
//                     'sais_Media' => 'Sais/Media',
//                     'dtdemo_Work' => 'Songs',
//                     'dtdemo_Jeopardy' => 'Jeopardy',
//                     'dummy_Product' => 'products',
//                     'dummy_products' => 'products!!'
//                 ] as $indexName => $label) {
            try {
//                $index = $this->meiliService->getIndex($indexName);
            } catch (\Exception $e) {
                continue;
            }
            $indexName = $index->getUid();
            $label = $indexName;
            if ($indexName !== 'dtdemo_Instrument') {
//                continue;
//                dd($index->getEmbedders(), $endpoint->settings, $index->getUid());
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


        $io->success(self::class . " success. " . $this->endpointRepository->count() );
		return Command::SUCCESS;
	}
}
