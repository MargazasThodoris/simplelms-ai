<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizAttempt;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizAttempt>
 */
class QuizAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizAttempt::class);
    }

    /** @return QuizAttempt[] */
    public function findRecentByUser(User $user, int $limit = 20, ?string $courseId = null): array
    {
        $qb = $this->createQueryBuilder('qa')
            ->andWhere('qa.user = :user')
            ->setParameter('user', $user)
            ->orderBy('qa.attemptedAt', 'DESC')
            ->setMaxResults($limit);

        if ($courseId !== null) {
            $qb->andWhere('IDENTITY(qa.course) = :courseId')
               ->setParameter('courseId', $courseId);
        }

        return $qb->getQuery()->getResult();
    }
}
