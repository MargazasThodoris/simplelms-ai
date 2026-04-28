<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AI;

use App\Entity\AITutorSession;
use App\Entity\Course;
use App\Entity\User;
use App\Repository\AITutorSessionRepository;
use App\Service\AI\AITutorService;
use App\Service\AI\SentimentAnalysisService;
use Doctrine\ORM\EntityManagerInterface;
use OpenAI\Client as OpenAIClient;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AITutorServiceTest extends TestCase
{
    private AITutorService $service;
    private OpenAIClient&MockObject $openAI;
    private EntityManagerInterface&MockObject $em;
    private SentimentAnalysisService&MockObject $sentimentService;

    protected function setUp(): void
    {
        $this->openAI           = $this->createMock(OpenAIClient::class);
        $this->em               = $this->createMock(EntityManagerInterface::class);
        $sessionRepo            = $this->createMock(AITutorSessionRepository::class);
        $this->sentimentService = $this->createMock(SentimentAnalysisService::class);

        $this->service = new AITutorService(
            openAI:           $this->openAI,
            em:               $this->em,
            sessionRepo:      $sessionRepo,
            sentimentService: $this->sentimentService,
            logger:           new NullLogger(),
            model:            'gpt-4o',
        );
    }

    public function testStartSessionCreatesActiveSession(): void
    {
        $user = $this->createUserMock();

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $session = $this->service->startSession($user, AITutorSession::MODE_CHAT);

        $this->assertSame(AITutorSession::MODE_CHAT, $session->getMode());
        $this->assertSame(AITutorSession::STATUS_ACTIVE, $session->getStatus());
        $this->assertCount(1, $session->getMessages()); // system prompt
        $this->assertSame('system', $session->getMessages()[0]['role']);
    }

    public function testStartRoleplaySessionInjectsPersona(): void
    {
        $user    = $this->createUserMock();
        $persona = ['name' => 'Angry Customer', 'scenario' => 'Billing dispute', 'personality' => 'hostile'];

        $this->em->method('persist');
        $this->em->method('flush');

        $session = $this->service->startSession($user, AITutorSession::MODE_ROLEPLAY, null, $persona);

        $this->assertSame(AITutorSession::MODE_ROLEPLAY, $session->getMode());
        $this->assertSame($persona, $session->getPersona());
        $systemPrompt = $session->getMessages()[0]['content'];
        $this->assertStringContainsString('Angry Customer', $systemPrompt);
        $this->assertStringContainsString('Billing dispute', $systemPrompt);
    }

    public function testChatAddsUserAndAssistantMessages(): void
    {
        $user    = $this->createUserMock();
        $session = new AITutorSession();
        $session->setUser($user);
        $session->setMode(AITutorSession::MODE_CHAT);
        $session->addMessage('system', 'You are a tutor.');

        $mockChatApi = $this->createMock(\OpenAI\Resources\Chat::class);
        $this->openAI->method('chat')->willReturn($mockChatApi);

        $mockResponse = $this->createMockChatResponse('That is a great question!', 42);
        $mockChatApi->method('create')->willReturn($mockResponse);

        $this->em->expects($this->once())->method('flush');

        $result = $this->service->chat($session, 'What is a Pivot Table?');

        $this->assertSame('That is a great question!', $result['reply']);
        $this->assertSame(42, $result['tokens']);

        $messages = $session->getMessages();
        $this->assertCount(3, $messages); // system + user + assistant
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame('What is a Pivot Table?', $messages[1]['content']);
        $this->assertSame('assistant', $messages[2]['role']);
        $this->assertSame('That is a great question!', $messages[2]['content']);
    }

    public function testCompleteSessionRunsSentimentAndFeedback(): void
    {
        $user    = $this->createUserMock();
        $session = new AITutorSession();
        $session->setUser($user);
        $session->setMode(AITutorSession::MODE_ROLEPLAY);
        $session->addMessage('system', 'You are a persona.');
        $session->addMessage('user', 'Hello');
        $session->addMessage('assistant', 'Hi there.');

        $this->sentimentService
            ->expects($this->once())
            ->method('analyzeTranscript')
            ->willReturn(['score' => 0.78, 'label' => 'positive', 'breakdown' => []]);

        $mockChatApi = $this->createMock(\OpenAI\Resources\Chat::class);
        $this->openAI->method('chat')->willReturn($mockChatApi);

        $feedbackJson = json_encode([
            'overall_score'            => 82.5,
            'strengths'                => ['Good empathy'],
            'areas_for_improvement'    => ['Be more concise'],
            'specific_tips'            => [],
            'recommended_next_module'  => null,
        ]);
        $mockChatApi->method('create')->willReturn($this->createMockChatResponse($feedbackJson, 100));

        $this->em->expects($this->once())->method('flush');

        $completed = $this->service->completeSession($session);

        $this->assertSame(AITutorSession::STATUS_COMPLETED, $completed->getStatus());
        $this->assertNotNull($completed->getCompletedAt());
        $this->assertSame(0.78, $completed->getSentimentAnalysis()['score']);
        $this->assertSame(82.5, $completed->getOverallScore());
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createUserMock(): User
    {
        $user = new User();
        $user->setEmail('learner@test.com');
        $user->setFirstName('John');
        $user->setLastName('Doe');
        return $user;
    }

    private function createMockChatResponse(string $content, int $tokens): object
    {
        return new class($content, $tokens) {
            public object $choices;
            public object $usage;

            public function __construct(string $content, int $tokens)
            {
                $this->choices = new \ArrayObject([
                    (object) ['message' => (object) ['content' => $content]],
                ]);
                $this->usage = (object) ['totalTokens' => $tokens];
            }
        };
    }
}
