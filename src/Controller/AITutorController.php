<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AITutorSession;
use App\Repository\AITutorSessionRepository;
use App\Repository\CourseRepository;
use App\Service\AI\AITutorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\User;

#[Route('/api/v1/tutor', name: 'api_tutor_')]
#[IsGranted('ROLE_USER')]
final class AITutorController extends AbstractController
{
    public function __construct(
        private readonly AITutorService $tutorService,
        private readonly AITutorSessionRepository $sessionRepo,
        private readonly CourseRepository $courseRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * POST /api/v1/tutor/sessions
     * Start a new tutoring or role-play session.
     */
    #[Route('/sessions', name: 'start_session', methods: ['POST'])]
    public function startSession(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data     = json_decode($request->getContent(), true);
        $mode     = $data['mode'] ?? AITutorSession::MODE_CHAT;
        $courseId = $data['course_id'] ?? null;
        $persona  = $data['persona'] ?? null;

        $course = $courseId ? $this->courseRepo->find($courseId) : null;

        $session = $this->tutorService->startSession($user, $mode, $course, $persona);

        return $this->json([
            'session_id' => (string) $session->getId(),
            'mode'       => $session->getMode(),
            'status'     => $session->getStatus(),
            'started_at' => $session->getStartedAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/tutor/sessions/{id}/chat
     * Send a message and receive AI response.
     * Supports SSE streaming via ?stream=1.
     */
    #[Route('/sessions/{id}/chat', name: 'chat', methods: ['POST'])]
    public function chat(
        string $id,
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        $session = $this->sessionRepo->find($id);

        if (!$session || $session->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        if ($session->getStatus() !== AITutorSession::STATUS_ACTIVE) {
            return $this->json(['error' => 'Session is not active'], Response::HTTP_CONFLICT);
        }

        $data    = json_decode($request->getContent(), true);
        $message = trim($data['message'] ?? '');

        if (!$message) {
            return $this->json(['error' => 'Message is required'], Response::HTTP_BAD_REQUEST);
        }

        // Stream via Server-Sent Events
        if ($request->query->getBoolean('stream')) {
            return $this->streamResponse($session, $message);
        }

        // Standard synchronous response
        $result = $this->tutorService->chat($session, $message);

        return $this->json([
            'reply'       => $result['reply'],
            'tokens_used' => $result['tokens'],
            'session_id'  => (string) $session->getId(),
        ]);
    }

    /**
     * POST /api/v1/tutor/sessions/{id}/complete
     * End a session and retrieve AI feedback + sentiment analysis.
     */
    #[Route('/sessions/{id}/complete', name: 'complete', methods: ['POST'])]
    public function complete(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $session = $this->sessionRepo->find($id);

        if (!$session || $session->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        $session = $this->tutorService->completeSession($session);

        return $this->json([
            'session_id'        => (string) $session->getId(),
            'overall_score'     => $session->getOverallScore(),
            'sentiment'         => $session->getSentimentAnalysis(),
            'coaching_feedback' => $session->getCoachingFeedback(),
            'completed_at'      => $session->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * GET /api/v1/tutor/sessions/{id}/transcript
     * Download the full session transcript with metadata.
     */
    #[Route('/sessions/{id}/transcript', name: 'transcript', methods: ['GET'])]
    public function transcript(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $session = $this->sessionRepo->find($id);

        if (!$session || $session->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        $messages = array_filter(
            $session->getMessages(),
            fn($m) => $m['role'] !== 'system'
        );

        return $this->json([
            'session_id'        => (string) $session->getId(),
            'mode'              => $session->getMode(),
            'messages'          => array_values($messages),
            'overall_score'     => $session->getOverallScore(),
            'sentiment'         => $session->getSentimentAnalysis(),
            'coaching_feedback' => $session->getCoachingFeedback(),
            'duration_seconds'  => $session->getCompletedAt()
                ? $session->getStartedAt()->diff($session->getCompletedAt())->s
                : null,
        ]);
    }

    /**
     * Server-Sent Events streaming for real-time AI responses.
     */
    private function streamResponse(AITutorSession $session, string $message): StreamedResponse
    {
        return new StreamedResponse(function () use ($session, $message) {
            $result = $this->tutorService->chat($session, $message);

            // Simulate token-by-token streaming by word-splitting
            $words = explode(' ', $result['reply']);
            foreach ($words as $i => $word) {
                $data = json_encode(['token' => $word . ($i < count($words) - 1 ? ' ' : ''), 'done' => false]);
                echo "data: {$data}\n\n";
                ob_flush();
                flush();
                usleep(20000); // 20ms between tokens for smooth UX
            }

            $done = json_encode(['done' => true, 'tokens_used' => $result['tokens']]);
            echo "data: {$done}\n\n";
            ob_flush();
            flush();
        }, Response::HTTP_OK, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
