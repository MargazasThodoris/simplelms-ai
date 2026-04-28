<?php

declare(strict_types=1);

namespace App\AI\Client;

use App\AI\AIClientInterface;
use Symfony\Component\HttpClient\HttpClient;

final class AnthropicAdapter implements AIClientInterface
{
    private const API_URL     = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function __construct(private readonly string $apiKey) {}

    public function chat(array $messages, string $model, array $options = []): string
    {
        $systemParts          = [];
        $conversationMessages = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemParts[] = $message['content'];
            } else {
                $conversationMessages[] = $message;
            }
        }

        $payload = [
            'model'      => $model,
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'messages'   => $conversationMessages,
        ];

        if (!empty($systemParts)) {
            $payload['system'] = implode("\n\n", $systemParts);
        }

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        // response_format is OpenAI-specific; prompts already request JSON output
        unset($payload['response_format']);

        $http     = HttpClient::create();
        $response = $http->request('POST', self::API_URL, [
            'headers' => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'json' => $payload,
        ]);

        $body = $response->toArray();

        return $body['content'][0]['text'] ?? '';
    }

    public function embed(string $text, string $model): array
    {
        throw new \RuntimeException(
            'Anthropic does not provide an embeddings API. ' .
            'Set AI_PROVIDER=openai, AI_PROVIDER=gemini, or AI_PROVIDER=bedrock to use smart search.'
        );
    }
}