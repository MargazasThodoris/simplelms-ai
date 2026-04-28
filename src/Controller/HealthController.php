<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Health check endpoint consumed by ALB target group health checks.
 * Returns 200 only when all critical dependencies are reachable.
 */
final class HealthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {}

    #[Route('/api/v1/health', name: 'health', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $checks  = [];
        $healthy = true;

        // Database
        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'fail';
            $healthy = false;
        }

        // Redis cache
        try {
            $this->cache->get('health_check_ping', fn() => 'pong');
            $checks['cache'] = 'ok';
        } catch (\Throwable $e) {
            $checks['cache'] = 'fail';
            $healthy = false;
        }

        return $this->json([
            'status'  => $healthy ? 'healthy' : 'degraded',
            'checks'  => $checks,
            'version' => $_ENV['APP_VERSION'] ?? 'dev',
            'time'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $healthy ? 200 : 503);
    }
}
