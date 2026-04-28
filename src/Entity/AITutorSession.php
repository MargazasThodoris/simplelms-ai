<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AITutorSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: AITutorSessionRepository::class)]
#[ORM\Table(name: 'ai_tutor_sessions')]
class AITutorSession
{
    public const MODE_CHAT      = 'chat';
    public const MODE_ROLEPLAY  = 'roleplay';
    public const MODE_QUIZ      = 'quiz';

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABANDONED = 'abandoned';

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.ulid_generator')]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'tutorSessions')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Course::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Course $course = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $mode = self::MODE_CHAT;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_ACTIVE;

    /**
     * Full conversation history in OpenAI message format:
     * [['role' => 'system|user|assistant', 'content' => '...'], ...]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $messages = [];

    /**
     * Roleplay persona configuration:
     * ['name' => 'Difficult Client', 'scenario' => '...', 'personality' => '...']
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $persona = null;

    /** Sentiment Analysis result: ['score' => 0.72, 'label' => 'positive', 'breakdown' => [...]] */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $sentimentAnalysis = null;

    /** AI-generated coaching tips at session end */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $coachingFeedback = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $overallScore = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $totalTokensUsed = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->id = new Ulid();
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): Ulid { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }
    public function getCourse(): ?Course { return $this->course; }
    public function setCourse(?Course $course): self { $this->course = $course; return $this; }
    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): self { $this->mode = $mode; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getMessages(): array { return $this->messages; }
    public function setMessages(array $messages): self { $this->messages = $messages; return $this; }
    public function addMessage(string $role, string $content): self { $this->messages[] = ['role' => $role, 'content' => $content]; return $this; }
    public function getPersona(): ?array { return $this->persona; }
    public function setPersona(?array $persona): self { $this->persona = $persona; return $this; }
    public function getSentimentAnalysis(): ?array { return $this->sentimentAnalysis; }
    public function setSentimentAnalysis(?array $analysis): self { $this->sentimentAnalysis = $analysis; return $this; }
    public function getCoachingFeedback(): ?array { return $this->coachingFeedback; }
    public function setCoachingFeedback(?array $feedback): self { $this->coachingFeedback = $feedback; return $this; }
    public function getOverallScore(): ?float { return $this->overallScore; }
    public function setOverallScore(?float $score): self { $this->overallScore = $score; return $this; }
    public function getTotalTokensUsed(): int { return $this->totalTokensUsed; }
    public function addTokensUsed(int $tokens): self { $this->totalTokensUsed += $tokens; return $this; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $dt): self { $this->completedAt = $dt; return $this; }
}
