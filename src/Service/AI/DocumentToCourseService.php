<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\AI\AIClientInterface;
use App\Entity\Course;
use App\Entity\Module;
use App\Entity\User;
use App\Service\Course\CourseBuilderService;
use App\Service\Storage\S3Service;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * TalentCraft 2.0: Converts raw documents (PDF, DOCX, TXT) into
 * fully structured interactive courses with AI-generated objectives,
 * modules, quiz questions, and voiceover scripts.
 */
final class DocumentToCourseService
{
    private const CHUNK_SIZE = 4000; // characters per chunk sent to GPT

    public function __construct(
        private readonly AIClientInterface $ai,
        private readonly EntityManagerInterface $em,
        private readonly CourseBuilderService $courseBuilder,
        private readonly S3Service $s3,
        private readonly PdfParser $pdfParser,
        private readonly LoggerInterface $logger,
        private readonly string $model = 'gpt-4o',
    ) {}

    /**
     * Full pipeline: extract text → analyse → generate course structure → persist.
     *
     * @return Course The generated (draft) course
     */
    public function convert(string $s3Key, string $mimeType, User $author): Course
    {
        $this->logger->info('DocumentToCourse: starting conversion', ['s3Key' => $s3Key]);

        // 1. Extract text from document
        $rawText = $this->extractText($s3Key, $mimeType);

        // 2. Chunk & analyse learning objectives
        $analysis = $this->analyseDocument($rawText);

        // 3. Generate full course structure (modules + lessons + quizzes)
        $structure = $this->generateCourseStructure($rawText, $analysis);

        // 4. Persist course
        $course = $this->courseBuilder->buildFromStructure($structure, $author, $s3Key);

        $this->logger->info('DocumentToCourse: course created', [
            'courseId' => (string) $course->getId(),
            'modules'  => count($structure['modules']),
        ]);

        return $course;
    }

    /**
     * Step 1: Pull raw text from PDF / DOCX / TXT stored in S3.
     */
    private function extractText(string $s3Key, string $mimeType): string
    {
        $tmpPath = sys_get_temp_dir() . '/' . basename($s3Key);
        $this->s3->download($s3Key, $tmpPath);

        return match (true) {
            str_contains($mimeType, 'pdf')  => $this->extractPdf($tmpPath),
            str_contains($mimeType, 'word') => $this->extractDocx($tmpPath),
            default                          => file_get_contents($tmpPath) ?: '',
        };
    }

    private function extractPdf(string $path): string
    {
        $pdf  = $this->pdfParser->parseFile($path);
        return $pdf->getText();
    }

    private function extractDocx(string $path): string
    {
        // Use phpword to extract text from docx
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($path);
        $text    = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . "\n";
                }
            }
        }
        return $text;
    }

    /**
     * Step 2: Use GPT to extract core learning objectives & topic map.
     */
    private function analyseDocument(string $text): array
    {
        $sample = mb_substr($text, 0, 8000); // first 8k chars for analysis

        $content = $this->ai->chat(
            [
                ['role' => 'system', 'content' => 'You are an expert instructional designer. Analyze this document and extract structured learning metadata. Respond ONLY with valid JSON.'],
                ['role' => 'user', 'content' => <<<PROMPT
                    Document excerpt:
                    ---
                    {$sample}
                    ---

                    Return a JSON object with:
                    {
                        "title": "<concise course title>",
                        "description": "<2-3 sentence course description>",
                        "target_audience": "<who this is for>",
                        "estimated_minutes": <integer>,
                        "learning_objectives": ["<verb-led objective>", ...],
                        "key_topics": ["<topic>", ...],
                        "complexity_level": "beginner|intermediate|advanced"
                    }
                    PROMPT
                ],
            ],
            $this->model,
            ['max_tokens' => 800, 'response_format' => ['type' => 'json_object']]
        );

        return json_decode($content, true) ?? [];
    }

    /**
     * Step 3: Generate full module/lesson/quiz structure from the full document.
     */
    private function generateCourseStructure(string $text, array $analysis): array
    {
        $chunks   = $this->chunkText($text);
        $modules  = [];

        foreach ($chunks as $index => $chunk) {
            $moduleNumber = $index + 1;
            $moduleContent = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => 'You are an expert instructional designer creating e-learning content. Respond ONLY with valid JSON.'],
                    ['role' => 'user', 'content' => <<<PROMPT
                        Create module #{$moduleNumber} for the course "{$analysis['title']}" based on this content section:
                        ---
                        {$chunk}
                        ---

                        Return a JSON object with:
                        {
                            "title": "<module title>",
                            "position": {$moduleNumber},
                            "learning_objectives": ["<objective>", ...],
                            "lessons": [
                                {
                                    "title": "<lesson title>",
                                    "content_html": "<rich HTML content with headings, lists, examples>",
                                    "voiceover_script": "<natural spoken narration script for text-to-speech>",
                                    "key_concepts": ["<concept>", ...]
                                }
                            ],
                            "quiz": {
                                "title": "<quiz title>",
                                "questions": [
                                    {
                                        "text": "<question>",
                                        "type": "multiple_choice|true_false|open_ended",
                                        "options": ["<option>", ...],
                                        "correct_answer": "<answer>",
                                        "explanation": "<why this is correct>"
                                    }
                                ]
                            }
                        }
                        PROMPT
                    ],
                ],
                $this->model,
                ['max_tokens' => 3000, 'response_format' => ['type' => 'json_object']]
            );

            $moduleData = json_decode($moduleContent, true);
            if ($moduleData) {
                $modules[] = $moduleData;
            }
        }

        return array_merge($analysis, ['modules' => $modules]);
    }

    /**
     * Split large document into overlapping chunks for GPT context limits.
     */
    private function chunkText(string $text): array
    {
        $chunks   = [];
        $length   = mb_strlen($text);
        $overlap  = 200;
        $position = 0;

        while ($position < $length) {
            $chunk     = mb_substr($text, $position, self::CHUNK_SIZE);
            $chunks[]  = $chunk;
            $position += self::CHUNK_SIZE - $overlap;
        }

        return $chunks;
    }
}
