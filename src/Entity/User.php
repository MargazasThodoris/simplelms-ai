<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\Index(columns: ['email'], name: 'idx_user_email')]
#[ORM\Index(columns: ['department_id', 'engagement_score'], name: 'idx_user_dept_engagement')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.ulid_generator')]
    private Ulid $id;

    #[ORM\Column(type: Types::STRING, length: 180, unique: true)]
    private string $email;

    #[ORM\Column(type: Types::JSON)]
    private array $roles = ['ROLE_LEARNER'];

    #[ORM\Column(type: Types::STRING)]
    private string $password;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $firstName;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $lastName;

    #[ORM\ManyToOne(targetEntity: Department::class, inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Department $department = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $jobTitle = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $skills = [];

    /**
     * Composite AI-generated score: login frequency, quiz results,
     * video watch %, assignment submissions (0–100).
     */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 100.0])]
    private float $engagementScore = 100.0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastActiveAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $aiPersonalizationProfile = null;

    /** Predicted % probability of missing next compliance deadline */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $atRiskScore = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    /** @var Collection<int, Enrollment> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Enrollment::class, cascade: ['persist', 'remove'])]
    private Collection $enrollments;

    /** @var Collection<int, QuizAttempt> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: QuizAttempt::class)]
    private Collection $quizAttempts;

    /** @var Collection<int, AITutorSession> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: AITutorSession::class)]
    private Collection $tutorSessions;

    public function __construct()
    {
        $this->id = new Ulid();
        $this->enrollments = new ArrayCollection();
        $this->quizAttempts = new ArrayCollection();
        $this->tutorSessions = new ArrayCollection();
    }

    public function getId(): Ulid { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = $email; return $this; }
    public function getUserIdentifier(): string { return $this->email; }
    public function getRoles(): array { return array_unique([...$this->roles, 'ROLE_USER']); }
    public function setRoles(array $roles): self { $this->roles = $roles; return $this; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): self { $this->password = $password; return $this; }
    public function eraseCredentials(): void {}
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): self { $this->firstName = $firstName; return $this; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): self { $this->lastName = $lastName; return $this; }
    public function getFullName(): string { return "{$this->firstName} {$this->lastName}"; }
    public function getDepartment(): ?Department { return $this->department; }
    public function setDepartment(?Department $department): self { $this->department = $department; return $this; }
    public function getJobTitle(): ?string { return $this->jobTitle; }
    public function setJobTitle(?string $jobTitle): self { $this->jobTitle = $jobTitle; return $this; }
    public function getSkills(): array { return $this->skills ?? []; }
    public function setSkills(array $skills): self { $this->skills = $skills; return $this; }
    public function getEngagementScore(): float { return $this->engagementScore; }
    public function setEngagementScore(float $score): self { $this->engagementScore = $score; return $this; }
    public function getLastActiveAt(): ?\DateTimeImmutable { return $this->lastActiveAt; }
    public function setLastActiveAt(?\DateTimeImmutable $dt): self { $this->lastActiveAt = $dt; return $this; }
    public function getAiPersonalizationProfile(): ?array { return $this->aiPersonalizationProfile; }
    public function setAiPersonalizationProfile(?array $profile): self { $this->aiPersonalizationProfile = $profile; return $this; }
    public function getAtRiskScore(): ?float { return $this->atRiskScore; }
    public function setAtRiskScore(?float $score): self { $this->atRiskScore = $score; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $active): self { $this->isActive = $active; return $this; }
    public function getEnrollments(): Collection { return $this->enrollments; }
    public function getQuizAttempts(): Collection { return $this->quizAttempts; }
    public function getTutorSessions(): Collection { return $this->tutorSessions; }
}
