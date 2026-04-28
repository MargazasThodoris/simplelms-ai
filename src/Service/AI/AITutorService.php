<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Entity\AITutorSession;
use App\Entity\Course;
use App\Entity\User;
use App\Repository\AITutorSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenAI\Client as OpenAIClient;
use Psr\Log\LoggerInterface;

/**
 * Manages AI-powered tutoring: conversational coaching, dynamic role-play simulations,
 * sentiment analysis, and personalised coaching feedback.
 */
final class AITutorService
{
    private const SYSTEM_COACH = <<<PROMPT
        You are an expert learning coach inside TalentLMS. Your role is to guide learners
        through concepts in a Socratic way — ask questions, probe understanding, give hints
        before answers. Be concise, encouraging, and adapt your language to the learner's
        demonstrated level. When the learner answers correctly, reinforce the concept.
        When they struggle, break it down further. Never just give the answer outright.
        PROMPT;

    public function __construct(
        private readonly OpenAIClient $openAI,
        private readonly EntityManagerInterface $em,
        private readonly AITutorSessionRepository $sessionRepo,
        private readonly SentimentAnalysisService $sentimentService,
        private readonly LoggerInterface $logger,
        private readonly string $model = 'gpt-4o',
    ) {}

    /**
     * Create a new tutoring session (chat or role-play).
     */
    public function startSession(
        User $user,
        string $mode = AITutorSession::MODE_CHAT,
        ?Course $course = null,
        ?array $persona = null,
    ): AITutorSession {
        $session = new AITutorSession();
        $session->setUser($user);
        $session->setCourse($course);
        $session->setMode($mode);

        if ($mode === AITutorSession::MODE_ROLEPLAY && $persona) {
            $session->setPersona($persona);
            $systemPrompt = $this->buildRoleplaySystemPrompt($persona, $course);
        } else {
            $systemPrompt = $this->buildCoachSystemPrompt($user, $course);
        }

        $session->addMessage('system', $systemPrompt);

        $this->em->persist($session);
        $this->em->flush();

        return $session;
    }

    /**
     * Send a user message and get AI response. Streams via SSE in the controller.
     *
     * @return array{reply: string, tokens: int, session: AITutorSession}
     */
    public function chat(AITutorSession $session, string $userMessage): array
    {
        $session->addMessage('user', $userMessage);

        try {
            $response = $this->openAI->chat()->create([
                'model'       => $this->model,
                'messages'    => $session->getMessages(),
                'max_tokens'  => 800,
                'temperature' => $session->getMode() === AITutorSession::MODE_ROLEPLAY ? 0.9 : 0.6,
            ]);

            $reply  = $response->choices[0]->message->content ?? '';
            $tokens = $response->usage->totalTokens ?? 0;

            $session->addMessage('assistant', $reply);
            $session->addTokensUsed($tokens);

            $this->em->flush();

            return ['reply' => $reply, 'tokens' => $tokens, 'session' => $session];
        } catch (\Throwable $e) {
            $this->logger->error('AITutor chat error', ['error' => $e->getMessage(), 'session' => (string) $session->getId()]);
            throw $e;
        }
    }

    /**
     * End a session: run sentiment analysis on the full transcript
     * and generate personalised coaching tips.
     */
    public function completeSession(AITutorSession $session): AITutorSession
    {
        $transcript = $this->buildTranscript($session);

        // Sentiment analysis
        $sentiment = $this->sentimentService->analyzeTranscript($transcript);
        $session->setSentimentAnalysis($sentiment);

        // Coaching feedback
        $feedback = $this->generateCoachingFeedback($session, $transcript);
        $session->setCoachingFeedback($feedback);
        $session->setOverallScore($feedback['overall_score'] ?? null);
        $session->setStatus(AITutorSession::STATUS_COMPLETED);
        $session->setCompletedAt(new \DateTimeImmutable());

        $this->em->flush();

        return $session;
    }

    private function buildCoachSystemPrompt(User $user, ?Course $course): string
    {
        $context = $course ? "The learner is studying: {$course->getTitle()}." : '';
        $profile = $user->getAiPersonalizationProfile();
        $level   = $profile['skill_level'] ?? 'intermediate';

        return self::SYSTEM_COACH . "\n\nLearner level: {$level}. {$context}";
    }

    private function buildRoleplaySystemPrompt(array $persona, ?Course $course): string
    {
        $name      = $persona['name'] ?? 'Difficult Client';
        $scenario  = $persona['scenario'] ?? 'A frustrated customer calling about a billing issue.';
        $traits    = $persona['personality'] ?? 'impatient, interrupts frequently, speaks quickly';
        $objective = $course?->getTitle() ?? 'conflict resolution';

        return <<<PROMPT
            You are playing the role of "{$name}" in a realistic training simulation for learners
            practicing {$objective}.

            SCENARIO: {$scenario}

            YOUR PERSONALITY: {$traits}

            RULES:
            - Stay in character at all times. Never break the fourth wall.
            - React dynamically and realistically to the learner's responses.
            - Escalate or de-escalate tension based on how well the learner handles the situation.
            - If the learner responds poorly (dismissive, rude, no empathy), become more upset.
            - If the learner responds well (empathetic, solution-focused), gradually calm down.
            - Keep responses concise (2–4 sentences) as in a real conversation.
            PROMPT;
    }

    private function generateCoachingFeedback(AITutorSession $session, string $transcript): array
    {
        $mode = $session->getMode();
        $prompt = $mode === AITutorSession::MODE_ROLEPLAY
            ? "You are an expert communication coach. Analyze this role-play transcript and provide structured feedback."
            : "You are an expert learning coach. Analyze this tutoring session transcript and provide structured feedback.";

        $response = $this->openAI->chat()->create([
            'model'    => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => <<<PROMPT
                    Transcript:
                    {$transcript}

                    Respond ONLY with a JSON object with exactly these fields:
                    {
                        "overall_score": <float 0-100>,
                        "strengths": [<string>, ...],
                        "areas_for_improvement": [<string>, ...],
                        "specific_tips": [{"moment": <string>, "suggestion": <string>}, ...],
                        "recommended_next_module": <string|null>
                    }
                    PROMPT
                ],
            ],
            'max_tokens'      => 1000,
            'response_format' => ['type' => 'json_object'],
        ]);

        $content = $response->choices[0]->message->content ?? '{}';
        return json_decode($content, true) ?? [];
    }

    private function buildTranscript(AITutorSession $session): string
    {
        $lines = [];
        foreach ($session->getMessages() as $msg) {
            if ($msg['role'] === 'system') {
                continue;
            }
            $role  = strtoupper($msg['role']);
            $lines[] = "[{$role}]: {$msg['content']}";
        }
        return implode("\n\n", $lines);
    }
}
