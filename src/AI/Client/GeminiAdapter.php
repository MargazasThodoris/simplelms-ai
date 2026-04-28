<?php

declare(strict_types=1);

namespace App\AI\Client;

use App\AI\AIClientInterface;
use OpenAI;

/**
 * Delegates to Google Gemini via its OpenAI-compatible endpoint so we can
 * reuse the openai-php client without a separate Gemini SDK dependency.
 */
final class GeminiAdapter implements AIClientInterface
{
    private readonly OpenAIAdapter $adapter;

    public function __construct(string $apiKey)
    {
        $client = OpenAI::factory()
            ->withBaseUri('https://generativelanguage.googleapis.com/v1beta/openai')
            ->withApiKey($apiKey)
            ->make();

        $this->adapter = new OpenAIAdapter($client);
    }

    public function chat(array $messages, string $model, array $options = []): string
    {
        return $this->adapter->chat($messages, $model, $options);
    }

    public function embed(string $text, string $model): array
    {
        return $this->adapter->embed($text, $model);
    }
}