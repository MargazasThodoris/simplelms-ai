<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Department;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    /** @return User[] */
    public function findActiveWithRecentActivity(?int $organizationId = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.isActive = true')
            ->andWhere('u.lastActiveAt >= :cutoff')
            ->setParameter('cutoff', new \DateTimeImmutable('-90 days'));

        if ($organizationId !== null) {
            $qb->join('u.department', 'd')
               ->andWhere('d.organizationId = :orgId')
               ->setParameter('orgId', $organizationId);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return User[] */
    public function findAtRiskAboveThreshold(?int $organizationId, float $threshold, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.isActive = true')
            ->andWhere('u.atRiskScore >= :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('u.atRiskScore', 'DESC')
            ->setMaxResults($limit);

        if ($organizationId !== null) {
            $qb->join('u.department', 'd')
               ->andWhere('d.organizationId = :orgId')
               ->setParameter('orgId', $organizationId);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return User[] */
    public function findByDepartment(Department $department): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.department = :dept')
            ->andWhere('u.isActive = true')
            ->setParameter('dept', $department)
            ->getQuery()
            ->getResult();
    }

    public function getEngagementStats(?int $organizationId): array
    {
        $qb = $this->createQueryBuilder('u')
            ->select(
                'COUNT(u.id) AS total_users',
                'AVG(u.engagementScore) AS avg_engagement',
                'SUM(CASE WHEN u.atRiskScore >= 0.65 THEN 1 ELSE 0 END) AS at_risk_count',
                'SUM(CASE WHEN u.lastActiveAt >= :cutoff THEN 1 ELSE 0 END) AS active_last_30_days',
            )
            ->andWhere('u.isActive = true')
            ->setParameter('cutoff', new \DateTimeImmutable('-30 days'));

        if ($organizationId !== null) {
            $qb->join('u.department', 'd')
               ->andWhere('d.organizationId = :orgId')
               ->setParameter('orgId', $organizationId);
        }

        return $qb->getQuery()->getSingleResult();
    }

    public function countLoginsInPeriod(User $user, int $days): int
    {
        $result = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_login_log WHERE user_id = :id AND logged_in_at >= :since',
            ['id' => (string) $user->getId(), 'since' => (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s')],
        );

        return (int) $result;
    }

    public function countTutorSessionsInPeriod(User $user, int $days): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(ts.id)')
            ->join('u.tutorSessions', 'ts')
            ->andWhere('u = :user')
            ->andWhere('ts.startedAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', new \DateTimeImmutable("-{$days} days"))
            ->getQuery()
            ->getSingleScalarResult();
    }
}