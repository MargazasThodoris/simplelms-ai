<?php

declare(strict_types=1);

namespace App\Message;

final readonly class IndexContentMessage
{
    public function __construct(
        public string $contentId,
        public string $contentType,
        public string $text,
    ) {}
}