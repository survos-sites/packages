<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Package;
use Doctrine\ORM\EntityManagerInterface;
use Survos\WorkflowMessengerBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;

final readonly class PackageSubjectStore implements SubjectStoreInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function reference(object $subject): SubjectReference
    {
        if (!$subject instanceof Package) throw new \InvalidArgumentException('Unsupported workflow subject.');
        return PackagePipeline::reference($subject);
    }

    public function load(SubjectReference $reference): ?object
    {
        $this->validate($reference);
        $package = $this->em->find(Package::class, $reference->id);
        if ($package !== null) {
            $this->em->refresh($package);
        }
        return $package;
    }

    public function save(SubjectReference $reference, object $subject, array $context = []): void
    {
        $this->validate($reference);
        if (!$subject instanceof Package || $subject->id !== $reference->id) {
            throw new \InvalidArgumentException('Mismatched package subject reference.');
        }
        if ($this->em->getConnection()->isTransactionActive()) {
            throw new \LogicException('Workflow continuation requires committed storage; use an outbox for outer transactions.');
        }
        $this->em->flush();
    }

    private function validate(SubjectReference $reference): void
    {
        if ($reference->type !== 'package') {
            throw new \InvalidArgumentException('Unsupported subject type: '.$reference->type);
        }
    }
}
