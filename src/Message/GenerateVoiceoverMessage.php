<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateVoiceoverMessage
{
    public function __construct(
        public string $lessonId,
        public string $courseId,
        public string $lessonTitle,
        public string $contentHtml,
    ) {}
}