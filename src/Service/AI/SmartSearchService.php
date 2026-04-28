<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\AI\AIClientInterface;
use App\Entity\Course;
use App\Repository\ContentChunkRepository;
use App\Repository\CourseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * AI-powered "Search Everything": performs RAG (Retrieval-Augmented Generation)
 * across all LMS content — PDFs, videos (via transcripts), SCORM, policies —
 * returning a direct answer with timestamped source links.
 */
final class SmartSearchService
{
    private const TOP_K_CHUNKS = 8;

    public function __construct(
        private readonly AIClientInterface $ai,
        private readonly EntityManagerInterface $em,
        private readonly CourseRepository $courseRepo,
        private readonly ContentChunkRepository $chunkRepo,
        private readonly LoggerInterface $logger,
        private readonly string $model = 'gpt-4o',
        private readonly string $embeddingModel = 'text-embedding-3-large',
    ) {}

    /**
     * Main entry point: answer a natural language question using RAG.
     *
     * @return array{answer: string, sources: list<array{title:string, url:string, excerpt:string}>}
     */
    public function search(string $query, ?int $organizationId = null): array
    {
        $this->logger->info('SmartSearch query', ['query' => $query]);

        // 1. Embed the query
        $queryEmbedding = $this->embed($query);

        // 2. Retrieve top-K semantically similar content chunks
        $chunks = $this->chunkRepo->findSimilar($queryEmbedding, self::TOP_K_CHUNKS, $organizationId);

        if (empty($chunks)) {
            return [
                'answer'  => "I couldn't find relevant content in the knowledge base for your question.",
                'sources' => [],
            ];
        }

        // 3. Build context from chunks
        $context = $this->buildContext($chunks);
        $sources = $this->extractSources($chunks);

        // 4. Generate a grounded answer via GPT
        $answer = $this->generateAnswer($query, $context);

        return ['answer' => $answer, 'sources' => $sources];
    }

    /**
     * Index a piece of content: split into chunks, embed, persist for retrieval.
     */
    public function indexContent(
        string $contentId,
        string $contentType,
        string $text,
        array $metadata = [],
    ): void {
        $chunks = $this->splitIntoChunks($text, chunkSize: 512, overlap: 64);

        foreach ($chunks as $position => $chunkText) {
            $embedding = $this->embed($chunkText);
            $this->chunkRepo->upsert(
                contentId:   $contentId,
                contentType: $contentType,
                position:    $position,
                text:        $chunkText,
                embedding:   $embedding,
                metadata:    $metadata,
            );
        }

        $this->logger->info('SmartSearch: indexed content', [
            'contentId'   => $contentId,
            'contentType' => $contentType,
            'chunks'      => count($chunks),
        ]);
    }

    /**
     * Generate an OpenAI text embedding for a string.
     */
    public function embed(string $text): array
    {
        return $this->ai->embed($text, $this->embeddingModel);
    }

    private function generateAnswer(string $query, string $context): string
    {
        return $this->ai->chat(
            [
                [
                    'role'    => 'system',
                    'content' => <<<PROMPT
                        You are an intelligent knowledge assistant for a learning management system.
                        Answer the user's question based ONLY on the provided context.
                        Be concise and direct. If the answer involves a policy or specific rule,
                        quote the relevant part. If the context doesn't contain the answer,
                        say so clearly — do not hallucinate.
                        PROMPT,
                ],
                [
                    'role'    => 'user',
                    'content' => "Context:\n{$context}\n\nQuestion: {$query}",
                ],
            ],
            $this->model,
            ['max_tokens' => 600, 'temperature' => 0.2]
        );
    }

    private function buildContext(array $chunks): string
    {
        return implode("\n\n---\n\n", array_map(
            fn($chunk) => "[Source: {$chunk['metadata']['title']}]\n{$chunk['text']}",
            $chunks
        ));
    }

    private function extractSources(array $chunks): array
    {
        $seen    = [];
        $sources = [];

        foreach ($chunks as $chunk) {
            $id = $chunk['content_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $sources[] = [
                'title'     => $chunk['metadata']['title'] ?? 'Unknown',
                'url'       => $chunk['metadata']['url'] ?? '#',
                'type'      => $chunk['content_type'],
                'timestamp' => $chunk['metadata']['timestamp'] ?? null,
                'excerpt'   => mb_substr($chunk['text'], 0, 200) . '...',
            ];
        }

        return $sources;
    }

    private function splitIntoChunks(string $text, int $chunkSize, int $overlap): array
    {
        $chunks   = [];
        $words    = explode(' ', $text);
        $total    = count($words);
        $step     = $chunkSize - $overlap;

        for ($i = 0; $i < $total; $i += $step) {
            $chunk = implode(' ', array_slice($words, $i, $chunkSize));
            if (trim($chunk)) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }
}
