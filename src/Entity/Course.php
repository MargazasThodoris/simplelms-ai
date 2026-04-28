<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CourseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: CourseRepository::class)]
#[ORM\Table(name: 'courses')]
class Course
{
    use TimestampableEntity;

    public const STATUS_DRAFT    = 'draft';
    public const STATUS_REVIEW   = 'review';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const SOURCE_MANUAL     = 'manual';
    public const SOURCE_AI_GENERATED = 'ai_generated';
    public const SOURCE_DOCUMENT    = 'document_import';

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.ulid_generator')]
    private Ulid $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => self::STATUS_DRAFT])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => self::SOURCE_MANUAL])]
    private string $source = self::SOURCE_MANUAL;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $learningObjectives = null;

    /** S3 key for cover image */
    #[ORM\Column(type: Types::STRING, nullable: true)]
    private ?string $coverImageKey = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $estimatedMinutes = 0;

    /** OpenAI vector embedding stored as JSON float array */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $embedding = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $author;

    /** @var Collection<int, Module> */
    #[ORM\OneToMany(mappedBy: 'course', targetEntity: Module::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $modules;

    /** @var Collection<int, Enrollment> */
    #[ORM\OneToMany(mappedBy: 'course', targetEntity: Enrollment::class)]
    private Collection $enrollments;

    /** Raw source document S3 key (for document-to-course imports) */
    #[ORM\Column(type: Types::STRING, nullable: true)]
    private ?string $sourceDocumentKey = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $aiGenerationMetadata = null;

    public function __construct()
    {
        $this->id = new Ulid();
        $this->modules = new ArrayCollection();
        $this->enrollments = new ArrayCollection();
    }

    public function getId(): Ulid { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $desc): self { $this->description = $desc; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $source): self { $this->source = $source; return $this; }
    public function getLearningObjectives(): ?array { return $this->learningObjectives; }
    public function setLearningObjectives(?array $obj): self { $this->learningObjectives = $obj; return $this; }
    public function getCoverImageKey(): ?string { return $this->coverImageKey; }
    public function setCoverImageKey(?string $key): self { $this->coverImageKey = $key; return $this; }
    public function getEstimatedMinutes(): int { return $this->estimatedMinutes; }
    public function setEstimatedMinutes(int $min): self { $this->estimatedMinutes = $min; return $this; }
    public function getEmbedding(): ?array { return $this->embedding; }
    public function setEmbedding(?array $emb): self { $this->embedding = $emb; return $this; }
    public function getAuthor(): User { return $this->author; }
    public function setAuthor(User $author): self { $this->author = $author; return $this; }
    public function getModules(): Collection { return $this->modules; }
    public function getEnrollments(): Collection { return $this->enrollments; }
    public function getSourceDocumentKey(): ?string { return $this->sourceDocumentKey; }
    public function setSourceDocumentKey(?string $key): self { $this->sourceDocumentKey = $key; return $this; }
    public function getAiGenerationMetadata(): ?array { return $this->aiGenerationMetadata; }
    public function setAiGenerationMetadata(?array $meta): self { $this->aiGenerationMetadata = $meta; return $this; }

    public function addModule(Module $module): self
    {
        if (!$this->modules->contains($module)) {
            $this->modules->add($module);
            $module->setCourse($this);
        }
        return $this;
    }
}
