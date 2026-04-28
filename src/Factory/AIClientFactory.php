<?php

declare(strict_types=1);

namespace App\Factory;

use App\AI\AIClientInterface;
use App\AI\Client\AnthropicAdapter;
use App\AI\Client\BedrockAdapter;
use App\AI\Client\GeminiAdapter;
use App\AI\Client\OpenAIAdapter;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use OpenAI;

final class AIClientFactory
{
    public static function create(
        string $provider,
        string $openaiApiKey,
        string $ollamaBaseUrl,
        string $anthropicApiKey,
        string $geminiApiKey,
        string $awsBedrockRegion,
    ): AIClientInterface {
        return match ($provider) {
            'openai'  => new OpenAIAdapter(OpenAI::client($openaiApiKey)),
            'ollama'  => new OpenAIAdapter(
                OpenAI::factory()
                    ->withBaseUri(rtrim($ollamaBaseUrl, '/'))
                    ->withApiKey('ollama') // Ollama ignores the key; SDK requires a non-empty value
                    ->make()
            ),
            'claude'  => new AnthropicAdapter($anthropicApiKey),
            'gemini'  => new GeminiAdapter($geminiApiKey),
            'bedrock' => new BedrockAdapter(new BedrockRuntimeClient([
                'version' => 'latest',
                'region'  => $awsBedrockRegion,
            ])),
            default   => throw new \InvalidArgumentException("Unknown AI provider: {$provider}"),
        };
    }
}