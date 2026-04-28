<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ConvertDocumentToCourseMessage
{
    public function __construct(
        public string $s3Key,
        public string $mimeType,
        public string $authorId,
        public string $originalName,
    ) {}
}
