<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SendRetentionNudgeMessage
{
    public function __construct(
        public string $userId,
        public float  $atRiskScore,
        public array  $signals,
    ) {}
}