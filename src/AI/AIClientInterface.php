<?php

declare(strict_types=1);

namespace App\AI;

interface AIClientInterface
{
    public function chat(array $messages, string $model, array $options = []): string;

    /** @return float[] */
    public function embed(string $text, string $model): array;
}