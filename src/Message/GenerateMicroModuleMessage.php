<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateMicroModuleMessage
{
    public function __construct(
        public string $userId,
        public string $topic,
        public array  $wrongAnswers = [],
    ) {}
}