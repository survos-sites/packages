<?php

declare(strict_types=1);

namespace App\Request;

use App\Entity\Catalog;
use App\Entity\Package;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Contracts\Cache\CacheInterface;

class ProjectValueConverter implements ValueResolverInterface
{
    //    use AppParameterTrait;

    public function __construct(
        private ManagerRegistry $registry,
        protected LoggerInterface $logger,
        protected EntityManagerInterface $entityManager,
        protected CacheInterface $cache,
        protected ParameterBagInterface $bag,
    ) {
    }

    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        // get the argument type (e.g. BookingId)
        $argumentType = $argument->getType();

        // get the value from the request, based on the argument name
        $value = $request->attributes->get($argument->getName());

        if (!is_subclass_of($argumentType, RouteParametersInterface::class)) {
            return [];
        }
        // the catalog classes are not persisted but may be loaded.  Here??
        if (!$em = $this->registry->getManagerForClass($argumentType)) {
            return [];
        }
        $repository = $em->getRepository($argumentType);

        $shortName = (new \ReflectionClass($argumentType))->getShortName();
        // not lovely...
        if ('Repo' == $shortName) {
            $idField = 'githubId';
        } else {
            $idField = lcfirst($shortName).'Id'; // e.g. projectId
        }

        if ($request->attributes->has($idField)) {
            $idFieldValue = $request->attributes->get($idField);
        } else {
            $idFieldValue = null;
            $this->logger->warning(sprintf('%s not found in %s', $idField, $argumentType));
            dd($idField, $request->attributes);
        }
        //        if ($argumentType == Catalog::class) {
        //            assert(false, "load catalog on demand here??");
        //            dd($value, $idFieldValue);
        //        }

        $value = match ($argumentType) {
            Package::class => $repository->findOneBy(['id' => $idFieldValue]),
            default => assert(false, "$argumentType not handled"),
        };

        // explicitly set the argument value for later reuse
        $request->attributes->set($argument->getName(), $value);

        return [$value];
    }
}
