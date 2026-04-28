<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Entity\User;
use App\Entity\QuizAttempt;
use App\Repository\QuizAttemptRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenAI\Client as OpenAIClient;
use Psr\Log\LoggerInterface;

/**
 * Hyper-Personalised "Just-in-Time" Micro-Module Generator.
 *
 * When a learner consistently misses questions on a specific topic,
 * this service generates a tailored 30-second micro-module that addresses
 * their exact point of confusion — not the whole topic from scratch.
 */
final class MicroModuleGeneratorService
{
    private const MIN_ATTEMPTS_FOR_ANALYSIS = 2;
    private const WEAK_THRESHOLD = 0.6; // below 60% = weak area

    public function __construct(
        private readonly OpenAIClient $openAI,
        private readonly EntityManagerInterface $em,
        private readonly QuizAttemptRepository $attemptRepo,
        private readonly LoggerInterface $logger,
        private readonly string $model = 'gpt-4o',
    ) {}

    /**
     * Analyse a user's recent quiz attempts and identify weak spots.
     * Returns a list of topics needing micro-modules.
     */
    public function identifyWeakAreas(User $user, ?string $courseId = null): array
    {
        $attempts = $this->attemptRepo->findRecentByUser($user, limit: 20, courseId: $courseId);

        $topicScores = [];
        foreach ($attempts as $attempt) {
            foreach ($attempt->getAnswers() as $answer) {
                $topic = $answer['topic'] ?? 'general';
                if (!isset($topicScores[$topic])) {
                    $topicScores[$topic] = ['correct' => 0, 'total' => 0];
                }
                $topicScores[$topic]['total']++;
                if ($answer['is_correct']) {
                    $topicScores[$topic]['correct']++;
                }
            }
        }

        $weakAreas = [];
        foreach ($topicScores as $topic => $scores) {
            if ($scores['total'] < self::MIN_ATTEMPTS_FOR_ANALYSIS) {
                continue;
            }
            $rate = $scores['correct'] / $scores['total'];
            if ($rate < self::WEAK_THRESHOLD) {
                $weakAreas[] = [
                    'topic'        => $topic,
                    'success_rate' => round($rate * 100, 1),
                    'attempts'     => $scores['total'],
                ];
            }
        }

        usort($weakAreas, fn($a, $b) => $a['success_rate'] <=> $b['success_rate']);

        return $weakAreas;
    }

    /**
     * Generate a personalised micro-module for a specific weak topic.
     *
     * Returns HTML content + a single reinforcement quiz question.
     * The module is designed to be consumed in ~30 seconds.
     */
    public function generate(User $user, string $topic, array $wrongAnswers = []): array
    {
        $wrongContext = $wrongAnswers
            ? "The learner specifically answered these incorrectly:\n" . implode("\n", array_map(
                fn($a) => "- Question: \"{$a['question']}\" → Their answer: \"{$a['user_answer']}\" (correct: \"{$a['correct_answer']}\")",
                array_slice($wrongAnswers, 0, 3)
            ))
            : '';

        $profile      = $user->getAiPersonalizationProfile();
        $level        = $profile['skill_level'] ?? 'intermediate';
        $learningStyle = $profile['learning_style'] ?? 'visual';

        $response = $this->openAI->chat()->create([
            'model'    => $this->model,
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => 'You are an expert instructional designer creating ultra-concise micro-learning content. Respond ONLY with JSON.',
                ],
                [
                    'role'    => 'user',
                    'content' => <<<PROMPT
                        Create a personalised micro-module (30-second read) for this learner:

                        Topic they are struggling with: "{$topic}"
                        Learner level: {$level}
                        Learning style preference: {$learningStyle}
                        {$wrongContext}

                        The micro-module must:
                        - Target EXACTLY what they got wrong (not a full topic overview)
                        - Use a concrete analogy or real-world example
                        - Be completable in under 30 seconds
                        - End with ONE reinforcement question

                        Return ONLY this JSON:
                        {
                            "title": "<micro-module title>",
                            "headline": "<one-sentence hook that names the misconception>",
                            "content_html": "<concise HTML: 2-3 short paragraphs + 1 example, max 150 words>",
                            "key_takeaway": "<single bold sentence — the core thing to remember>",
                            "analogy": "<a memorable analogy for this concept>",
                            "reinforcement_question": {
                                "text": "<question>",
                                "options": ["<A>", "<B>", "<C>", "<D>"],
                                "correct_index": <0-3>,
                                "explanation": "<why this is correct>"
                            },
                            "estimated_seconds": <integer 20-45>
                        }
                        PROMPT,
                ],
            ],
            'max_tokens'      => 900,
            'temperature'     => 0.5,
            'response_format' => ['type' => 'json_object'],
        ]);

        $module = json_decode($response->choices[0]->message->content ?? '{}', true) ?? [];

        $this->logger->info('Micro-module generated', [
            'userId'  => (string) $user->getId(),
            'topic'   => $topic,
        ]);

        return $module;
    }
}
