<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Package;
use Doctrine\ORM\EntityManagerInterface;
use Survos\WorkflowAsyncBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowAsyncBundle\Message\SubjectReference;

final readonly class PackageSubjectStore implements SubjectStoreInterface
{
    public function __construct(private EntityManagerInterface $em, private PackagePipeline $pipeline) {}

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
        $this->pipeline->finish($subject, $context);
    }

    private function validate(SubjectReference $reference): void
    {
        if ($reference->type !== 'package') {
            throw new \InvalidArgumentException('Unsupported subject type: '.$reference->type);
        }
    }
}
