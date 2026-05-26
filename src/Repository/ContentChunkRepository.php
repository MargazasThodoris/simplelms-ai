<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentChunk;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentChunk>
 */
class ContentChunkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentChunk::class);
    }

    /**
     * Returns the top-K chunks closest to $queryEmbedding using pgvector cosine distance.
     *
     * @param float[] $queryEmbedding
     * @return array<int, array{id: string, content_id: string, content_type: string, text: string, metadata: array, distance: float}>
     */
    public function findSimilar(array $queryEmbedding, int $topK = 8, ?int $organizationId = null): array
    {
        $vectorLiteral = '[' . implode(',', $queryEmbedding) . ']';

        $sql = <<<SQL
            SELECT id, content_id, content_type, text, metadata,
                   embedding <=> :embedding AS distance
            FROM content_chunks
            WHERE (:org_id::int IS NULL OR (metadata->>'organization_id')::int = :org_id)
            ORDER BY embedding <=> :embedding
            LIMIT :top_k
            SQL;

        $conn   = $this->getEntityManager()->getConnection();
        $result = $conn->executeQuery($sql, [
            'embedding' => $vectorLiteral,
            'org_id'    => $organizationId,
            'top_k'     => $topK,
        ]);

        return array_map(static function (array $row): array {
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
            return $row;
        }, $result->fetchAllAssociative());
    }

    /**
     * Insert or update a content chunk identified by (content_id, position).
     *
     * @param float[] $embedding
     */
    public function upsert(
        string $contentId,
        string $contentType,
        int    $position,
        string $text,
        array  $embedding,
        array  $metadata = [],
    ): void {
        $vectorLiteral = '[' . implode(',', $embedding) . ']';

        $sql = <<<SQL
            INSERT INTO content_chunks (id, content_id, content_type, position, text, embedding, metadata, created_at)
            VALUES (gen_random_uuid(), :content_id, :content_type, :position, :text, :embedding::vector, :metadata::jsonb, NOW())
            ON CONFLICT (content_id, position)
            DO UPDATE SET
                content_type = EXCLUDED.content_type,
                text         = EXCLUDED.text,
                embedding    = EXCLUDED.embedding,
                metadata     = EXCLUDED.metadata
            SQL;

        $this->getEntityManager()->getConnection()->executeStatement($sql, [
            'content_id'   => $contentId,
            'content_type' => $contentType,
            'position'     => $position,
            'text'         => $text,
            'embedding'    => $vectorLiteral,
            'metadata'     => json_encode($metadata),
        ]);
    }
}