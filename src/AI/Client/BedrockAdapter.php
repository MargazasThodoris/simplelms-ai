<?php

declare(strict_types=1);

namespace App\AI\Client;

use App\AI\AIClientInterface;
use Aws\BedrockRuntime\BedrockRuntimeClient;

/**
 * Wraps Amazon Bedrock using the Converse API for chat and InvokeModel for
 * embeddings (Amazon Titan Embeddings V2).
 *
 * In production ECS tasks the client uses the task IAM role automatically.
 * For local dev set AWS_ACCESS_KEY_ID + AWS_SECRET_ACCESS_KEY in .env.local.
 */
final class BedrockAdapter implements AIClientInterface
{
    public function __construct(private readonly BedrockRuntimeClient $client) {}

    public function chat(array $messages, string $model, array $options = []): string
    {
        $system           = [];
        $converseMessages = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system[] = ['text' => $msg['content']];
            } else {
                $converseMessages[] = [
                    'role'    => $msg['role'],
                    'content' => [['text' => $msg['content']]],
                ];
            }
        }

        $params = [
            'modelId'         => $model,
            'messages'        => $converseMessages,
            'inferenceConfig' => [
                'maxTokens'   => $options['max_tokens'] ?? 1024,
                'temperature' => (float) ($options['temperature'] ?? 0.7),
            ],
        ];

        if (!empty($system)) {
            $params['system'] = $system;
        }

        $response = $this->client->converse($params);

        return $response['output']['message']['content'][0]['text'] ?? '';
    }

    public function embed(string $text, string $model): array
    {
        $response = $this->client->invokeModel([
            'modelId'     => $model,
            'contentType' => 'application/json',
            'accept'      => 'application/json',
            'body'        => json_encode(['inputText' => $text]),
        ]);

        $body = json_decode((string) $response['body'], true);

        return $body['embedding'] ?? [];
    }
}