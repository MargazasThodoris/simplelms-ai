<?php

declare(strict_types=1);

namespace App\AI\Client;

use App\AI\AIClientInterface;
use OpenAI\Client;

final class OpenAIAdapter implements AIClientInterface
{
    public function __construct(private readonly Client $client) {}

    public function chat(array $messages, string $model, array $options = []): string
    {
        $response = $this->client->chat()->create(array_merge([
            'model'    => $model,
            'messages' => $messages,
        ], $options));

        return $response->choices[0]->message->content ?? '';
    }

    public function embed(string $text, string $model): array
    {
        $response = $this->client->embeddings()->create([
            'model' => $model,
            'input' => $text,
        ]);

        return $response->embeddings[0]->embedding ?? [];
    }
}