<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\AI\AIClientInterface;
use App\Entity\User;
use App\Message\SendRetentionNudgeMessage;
use App\Repository\EnrollmentRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Predictive Retention Engine: analyses behavioural signals, computes
 * at-risk scores, and triggers personalised nudges before learners disengage.
 */
final class PredictiveRetentionService
{
    /** If at-risk score exceeds this threshold, trigger an alert */
    private const AT_RISK_THRESHOLD = 0.65;

    /** Minimum days of inactivity before running analysis */
    private const MIN_INACTIVITY_DAYS = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo,
        private readonly EnrollmentRepository $enrollmentRepo,
        private readonly AIClientInterface $ai,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly string $model = 'gpt-4o',
    ) {}

    /**
     * Run the full retention analysis sweep for an organization.
     * Called nightly via Symfony Scheduler / AWS EventBridge + ECS task.
     */
    public function runOrganizationSweep(int $organizationId): array
    {
        $atRiskUsers = [];
        $users = $this->userRepo->findActiveWithRecentActivity($organizationId);

        foreach ($users as $user) {
            $signals = $this->collectBehavioralSignals($user);
            $score   = $this->computeAtRiskScore($user, $signals);

            $user->setAtRiskScore($score);
            $user->setEngagementScore($this->computeEngagementScore($signals));

            if ($score >= self::AT_RISK_THRESHOLD) {
                $atRiskUsers[] = $user;
                $this->triggerRetentionAction($user, $signals, $score);
            }
        }

        $this->em->flush();

        $this->logger->info('Retention sweep completed', [
            'org'       => $organizationId,
            'analyzed'  => count($users),
            'at_risk'   => count($atRiskUsers),
        ]);

        return $atRiskUsers;
    }

    /**
     * Collect all quantitative behavioural signals for a user (last 14 days).
     */
    public function collectBehavioralSignals(User $user): array
    {
        $now      = new \DateTimeImmutable();
        $window   = $now->modify('-14 days');

        $enrollments     = $this->enrollmentRepo->findByUserSince($user, $window);
        $lastActive      = $user->getLastActiveAt();
        $daysSinceActive = $lastActive
            ? (int) $now->diff($lastActive)->days
            : 999;

        $completionRates  = [];
        $quizScores       = [];
        $videoWatchRates  = [];
        $overdueCount     = 0;

        foreach ($enrollments as $enrollment) {
            $completionRates[] = $enrollment->getProgressPercent();
            if ($enrollment->isOverdue()) {
                ++$overdueCount;
            }
        }

        return [
            'days_since_active'       => $daysSinceActive,
            'logins_last_7_days'      => $this->userRepo->countLoginsInPeriod($user, 7),
            'logins_last_14_days'     => $this->userRepo->countLoginsInPeriod($user, 14),
            'avg_completion_rate'     => empty($completionRates) ? 0 : array_sum($completionRates) / count($completionRates),
            'overdue_courses'         => $overdueCount,
            'avg_quiz_score'          => empty($quizScores) ? null : array_sum($quizScores) / count($quizScores),
            'video_watch_rate'        => empty($videoWatchRates) ? null : array_sum($videoWatchRates) / count($videoWatchRates),
            'ai_tutor_sessions_week'  => $this->userRepo->countTutorSessionsInPeriod($user, 7),
            'current_engagement_score' => $user->getEngagementScore(),
        ];
    }

    /**
     * Use GPT to interpret signals and output a structured risk assessment.
     *
     * For production: replace with a trained sklearn/XGBoost model via AWS SageMaker
     * endpoint for lower latency and cost; GPT is used here for rich explanations.
     */
    public function computeAtRiskScore(User $user, array $signals): float
    {
        $content = $this->ai->chat(
            [
                [
                    'role'    => 'system',
                    'content' => 'You are a learning analytics engine. Analyze learner engagement signals and output ONLY a JSON risk assessment.',
                ],
                [
                    'role'    => 'user',
                    'content' => <<<PROMPT
                        Analyze these behavioral signals for a learner and assess their risk of
                        disengagement or missing upcoming compliance deadlines:

                        Signals (last 14 days):
                        - Days since last login: {$signals['days_since_active']}
                        - Logins (last 7 days): {$signals['logins_last_7_days']}
                        - Logins (last 14 days): {$signals['logins_last_14_days']}
                        - Avg course completion rate: {$signals['avg_completion_rate']}%
                        - Overdue courses: {$signals['overdue_courses']}
                        - Avg quiz score: {$signals['avg_quiz_score']}
                        - Video watch rate: {$signals['video_watch_rate']}
                        - AI tutor sessions this week: {$signals['ai_tutor_sessions_week']}
                        - Current engagement score: {$signals['current_engagement_score']}

                        Return ONLY a JSON object:
                        {
                            "at_risk_score": <float 0.0-1.0>,
                            "risk_level": "low|medium|high|critical",
                            "primary_risk_factors": ["<factor>", ...],
                            "predicted_deadline_miss_probability": <float 0.0-1.0>,
                            "recommended_action": "<specific action for admin>",
                            "suggested_nudge_message": "<personalised message to learner>"
                        }
                        PROMPT,
                ],
            ],
            $this->model,
            ['max_tokens' => 500, 'temperature' => 0.1, 'response_format' => ['type' => 'json_object']]
        );

        $result = json_decode($content, true);
        return (float) ($result['at_risk_score'] ?? 0.0);
    }

    private function computeEngagementScore(array $signals): float
    {
        // Weighted formula based on behavioural signals
        $loginScore      = min(100, $signals['logins_last_7_days'] * 15);
        $completionScore = $signals['avg_completion_rate'];
        $quizScore       = ($signals['avg_quiz_score'] ?? 70);
        $activityBonus   = $signals['ai_tutor_sessions_week'] > 0 ? 10 : 0;
        $overdunePenalty = min(40, $signals['overdue_courses'] * 10);

        $raw = ($loginScore * 0.3) + ($completionScore * 0.3) + ($quizScore * 0.3) + $activityBonus - $overdunePenalty;
        return max(0, min(100, $raw));
    }

    private function triggerRetentionAction(User $user, array $signals, float $score): void
    {
        $this->logger->warning('At-risk learner detected', [
            'userId'    => (string) $user->getId(),
            'email'     => $user->getEmail(),
            'atRisk'    => $score,
            'overdue'   => $signals['overdue_courses'],
        ]);

        // Dispatch async nudge (email + in-app notification)
        $this->bus->dispatch(new SendRetentionNudgeMessage(
            userId:         (string) $user->getId(),
            atRiskScore:    $score,
            signals:        $signals,
        ));
    }
}
