<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Course;
use App\Message\ConvertDocumentToCourseMessage;
use App\Repository\UserRepository;
use App\Service\AI\DocumentToCourseService;
use App\Service\AI\SmartSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ConvertDocumentToCourseHandler
{
    public function __construct(
        private readonly DocumentToCourseService $converter,
        private readonly SmartSearchService $searchService,
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ConvertDocumentToCourseMessage $message): void
    {
        $this->logger->info('Processing document-to-course job', [
            's3Key'        => $message->s3Key,
            'originalName' => $message->originalName,
        ]);

        $author = $this->userRepo->find($message->authorId);
        if (!$author) {
            $this->logger->error('Author not found', ['authorId' => $message->authorId]);
            return;
        }

        try {
            // 1. Convert document to course
            $course = $this->converter->convert($message->s3Key, $message->mimeType, $author);
            $course->setStatus(Course::STATUS_REVIEW); // requires human review before publish
            $this->em->flush();

            // 2. Index course content for SmartSearch / RAG
            $this->indexCourseForSearch($course);

            $this->logger->info('Document-to-course conversion completed', [
                'courseId'   => (string) $course->getId(),
                'courseTitle' => $course->getTitle(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Document-to-course conversion failed', [
                's3Key' => $message->s3Key,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // re-throw so Messenger retries / moves to failed queue
        }
    }

    private function indexCourseForSearch(Course $course): void
    {
        foreach ($course->getModules() as $module) {
            foreach ($module->getLessons() as $lesson) {
                $text = strip_tags($lesson->getContentHtml() ?? '');
                if (!$text) {
                    continue;
                }

                $this->searchService->indexContent(
                    contentId:   (string) $lesson->getId(),
                    contentType: 'lesson',
                    text:        $text,
                    metadata:    [
                        'title'     => $lesson->getTitle(),
                        'course_id' => (string) $course->getId(),
                        'module_id' => (string) $module->getId(),
                        'url'       => "/courses/{$course->getId()}/modules/{$module->getId()}/lessons/{$lesson->getId()}",
                    ],
                );
            }
        }
    }
}
