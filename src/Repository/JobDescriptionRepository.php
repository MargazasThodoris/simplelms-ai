<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Department;
use App\Entity\JobDescription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobDescription>
 */
class JobDescriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobDescription::class);
    }

    /** @return JobDescription[] */
    public function findByDepartment(Department $department): array
    {
        return $this->createQueryBuilder('jd')
            ->andWhere('jd.department = :department')
            ->setParameter('department', $department)
            ->getQuery()
            ->getResult();
    }
}
