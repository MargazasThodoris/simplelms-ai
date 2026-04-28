<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\ConvertDocumentToCourseMessage;
use App\Service\Storage\S3Service;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\User;

#[Route('/api/v1/courses', name: 'api_courses_')]
#[IsGranted('ROLE_ADMIN')]
final class CourseGenerationController extends AbstractController
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'text/plain',
    ];

    private const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50 MB

    public function __construct(
        private readonly S3Service $s3,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * POST /api/v1/courses/generate-from-document
     *
     * Upload a document and kick off async AI course generation.
     * The heavy GPT processing is handled by a Symfony Messenger worker
     * consuming from the AWS SQS queue.
     */
    #[Route('/generate-from-document', name: 'generate_from_document', methods: ['POST'])]
    public function generateFromDocument(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $file = $request->files->get('document');

        if (!$file) {
            return $this->json(['error' => 'No document uploaded'], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return $this->json(['error' => 'File exceeds 50 MB limit'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $mimeType = $file->getMimeType() ?? '';
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return $this->json([
                'error'   => 'Unsupported file type',
                'allowed' => ['PDF', 'DOCX', 'DOC', 'TXT'],
            ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        // Upload original document to S3
        $s3Key = sprintf(
            'source-documents/%s/%s/%s',
            $user->getId(),
            date('Y/m'),
            uniqid() . '.' . $file->getClientOriginalExtension()
        );

        $this->s3->upload($s3Key, $file->getRealPath(), $mimeType);

        // Dispatch async conversion job to SQS → ECS worker
        $envelope = $this->bus->dispatch(new ConvertDocumentToCourseMessage(
            s3Key:        $s3Key,
            mimeType:     $mimeType,
            authorId:     (string) $user->getId(),
            originalName: $file->getClientOriginalName(),
        ));

        return $this->json([
            'message'   => 'Document uploaded. Course generation has started.',
            'job_id'    => $s3Key,
            'status'    => 'processing',
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * GET /api/v1/courses/generation-status/{jobId}
     * Poll generation status (or use WebSocket / SSE for push).
     */
    #[Route('/generation-status/{jobId}', name: 'generation_status', methods: ['GET'])]
    public function generationStatus(string $jobId): JsonResponse
    {
        // In production: check job status from Redis/DB
        // This is a simplified example
        return $this->json([
            'job_id' => $jobId,
            'status' => 'processing', // processing | completed | failed
        ]);
    }
}
