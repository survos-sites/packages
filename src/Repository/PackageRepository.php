<?php

namespace App\Repository;

use App\Entity\Package;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\FieldBundle\Repository\QueryBuilderHelperInterface;
use Survos\FieldBundle\Repository\QueryBuilderHelperTrait;

/**
 * @method Package|null find($id, $lockMode = null, $lockVersion = null)
 * @method Package|null findOneBy(array $criteria, array $orderBy = null)
 * @method Package[]    findAll()
 * @method Package[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PackageRepository extends ServiceEntityRepository implements QueryBuilderHelperInterface
{
    use QueryBuilderHelperTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Package::class);
    }

    /** @return array<string, int> */
    public function countByMarking(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.marking AS marking, COUNT(p.id) AS total')
            ->groupBy('p.marking')
            ->getQuery()->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $marking = $row['marking'];
            $counts[$marking instanceof \BackedEnum ? $marking->value : $marking] = (int) $row['total'];
        }
        return $counts;
    }

    // /**
    //  * @return Package[] Returns an array of Package objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Package
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
