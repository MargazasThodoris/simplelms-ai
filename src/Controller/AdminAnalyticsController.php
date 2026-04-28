<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Analytics\PredictiveRetentionService;
use App\Service\Analytics\SkillGapMappingService;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/v1/admin/analytics', name: 'api_admin_analytics_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminAnalyticsController extends AbstractController
{
    public function __construct(
        private readonly SkillGapMappingService $skillGapService,
        private readonly PredictiveRetentionService $retentionService,
        private readonly UserRepository $userRepo,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * GET /api/v1/admin/analytics/skill-gaps
     * Returns organization-wide skill gap heat map.
     * Results cached for 4 hours (expensive AI operation).
     */
    #[Route('/skill-gaps', name: 'skill_gaps', methods: ['GET'])]
    public function skillGaps(Request $request): JsonResponse
    {
        $orgId = $this->getUser()->getOrganizationId();
        $force = $request->query->getBoolean('refresh', false);

        $cacheKey = "skill_gaps_{$orgId}";
        if ($force) {
            $this->cache->delete($cacheKey);
        }

        $report = $this->cache->get($cacheKey, function (ItemInterface $item) use ($orgId): array {
            $item->expiresAfter(4 * 3600); // 4 hours
            return $this->skillGapService->generateOrganizationReport($orgId);
        });

        return $this->json($report);
    }

    /**
     * GET /api/v1/admin/analytics/at-risk-learners
     * Returns paginated list of at-risk learners with predicted failure probabilities.
     */
    #[Route('/at-risk-learners', name: 'at_risk', methods: ['GET'])]
    public function atRiskLearners(Request $request): JsonResponse
    {
        $orgId     = $this->getUser()->getOrganizationId();
        $threshold = (float) $request->query->get('threshold', '0.6');
        $limit     = min((int) $request->query->get('limit', 20), 100);

        $users = $this->userRepo->findAtRiskAboveThreshold($orgId, $threshold, $limit);

        $payload = array_map(function ($user) {
            $signals = $this->retentionService->collectBehavioralSignals($user);
            return [
                'user_id'           => (string) $user->getId(),
                'name'              => $user->getFullName(),
                'email'             => $user->getEmail(),
                'department'        => $user->getDepartment()?->getName(),
                'at_risk_score'     => $user->getAtRiskScore(),
                'engagement_score'  => $user->getEngagementScore(),
                'days_since_active' => $signals['days_since_active'],
                'overdue_courses'   => $signals['overdue_courses'],
                'logins_last_7d'    => $signals['logins_last_7_days'],
            ];
        }, $users);

        return $this->json([
            'total'   => count($payload),
            'learners' => $payload,
        ]);
    }

    /**
     * POST /api/v1/admin/analytics/at-risk-learners/{userId}/nudge
     * Manually trigger a retention nudge for a specific learner.
     */
    #[Route('/at-risk-learners/{userId}/nudge', name: 'nudge', methods: ['POST'])]
    public function sendNudge(string $userId): JsonResponse
    {
        $user = $this->userRepo->find($userId);
        if (!$user) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $signals = $this->retentionService->collectBehavioralSignals($user);
        $score   = $this->retentionService->computeAtRiskScore($user, $signals);

        // Directly dispatch nudge via service
        $this->retentionService->runOrganizationSweep($user->getOrganizationId());

        return $this->json([
            'message'       => 'Nudge dispatched successfully',
            'user_id'       => $userId,
            'at_risk_score' => $score,
        ]);
    }

    /**
     * GET /api/v1/admin/analytics/engagement-overview
     * High-level engagement metrics for the admin dashboard.
     */
    #[Route('/engagement-overview', name: 'engagement_overview', methods: ['GET'])]
    public function engagementOverview(): JsonResponse
    {
        $orgId = $this->getUser()->getOrganizationId();

        $stats = $this->cache->get("engagement_overview_{$orgId}", function (ItemInterface $item) use ($orgId) {
            $item->expiresAfter(1800); // 30 min
            return $this->userRepo->getEngagementStats($orgId);
        });

        return $this->json($stats);
    }
}
