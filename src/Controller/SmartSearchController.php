<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AI\SmartSearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Entity\User;

#[Route('/api/v1/search', name: 'api_search_')]
#[IsGranted('ROLE_USER')]
final class SmartSearchController extends AbstractController
{
    public function __construct(
        private readonly SmartSearchService $searchService,
        private readonly ValidatorInterface $validator,
    ) {}

    /**
     * GET /api/v1/search?q=What+is+our+policy+on+remote+work+in+France
     *
     * Performs RAG search across all indexed LMS content and returns
     * a direct answer with source citations.
     */
    #[Route('', name: 'search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $query = trim($request->query->get('q', ''));

        if (mb_strlen($query) < 3) {
            return $this->json(['error' => 'Query must be at least 3 characters'], Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($query) > 500) {
            return $this->json(['error' => 'Query must be at most 500 characters'], Response::HTTP_BAD_REQUEST);
        }

        $orgId = $user->getOrganizationId();

        $result = $this->searchService->search($query, $orgId);

        return $this->json([
            'query'   => $query,
            'answer'  => $result['answer'],
            'sources' => $result['sources'],
            'meta'    => [
                'total_sources' => count($result['sources']),
                'searched_at'   => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    /**
     * POST /api/v1/search/index
     * Admin-only: manually trigger re-indexing of a specific content item.
     */
    #[Route('/index', name: 'index_content', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function indexContent(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $contentId   = $data['content_id'] ?? null;
        $contentType = $data['content_type'] ?? null;
        $text        = $data['text'] ?? null;
        $metadata    = $data['metadata'] ?? [];

        if (!$contentId || !$contentType || !$text) {
            return $this->json(['error' => 'content_id, content_type, and text are required'], Response::HTTP_BAD_REQUEST);
        }

        $this->searchService->indexContent($contentId, $contentType, $text, $metadata);

        return $this->json(['message' => 'Content indexed successfully', 'content_id' => $contentId]);
    }
}
