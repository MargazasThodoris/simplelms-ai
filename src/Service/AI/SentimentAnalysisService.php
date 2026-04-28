<?php

declare(strict_types=1);

namespace App\Service\AI;

use OpenAI\Client as OpenAIClient;

/**
 * Analyse the sentiment and emotional tone of a roleplay/tutoring transcript.
 * Returns a structured score used on the learner's post-session summary card.
 */
final class SentimentAnalysisService
{
    public function __construct(
        private readonly OpenAIClient $openAI,
        private readonly string $model = 'gpt-4o',
    ) {}

    /**
     * @return array{
     *     score: float,
     *     label: string,
     *     breakdown: array<string, float>,
     *     emotional_arc: list<array{turn: int, sentiment: string}>,
     *     key_moments: list<array{turn: int, quote: string, note: string}>
     * }
     */
    public function analyzeTranscript(string $transcript): array
    {
        $response = $this->openAI->chat()->create([
            'model'    => $this->model,
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => 'You are a communication analysis AI. Analyze the sentiment and emotional dynamics of the following transcript. Respond ONLY with JSON.',
                ],
                [
                    'role'    => 'user',
                    'content' => <<<PROMPT
                        Analyze this conversation transcript for sentiment, emotional tone, and communication quality:

                        {$transcript}

                        Return ONLY this JSON structure:
                        {
                            "score": <float 0.0-1.0, where 1.0 = very positive/effective>,
                            "label": "very_positive|positive|neutral|negative|very_negative",
                            "breakdown": {
                                "empathy": <0.0-1.0>,
                                "clarity": <0.0-1.0>,
                                "confidence": <0.0-1.0>,
                                "professionalism": <0.0-1.0>,
                                "problem_solving": <0.0-1.0>
                            },
                            "emotional_arc": [
                                {"turn": <int>, "sentiment": "positive|neutral|negative", "intensity": <0.0-1.0>}
                            ],
                            "key_moments": [
                                {"turn": <int>, "type": "strength|weakness", "note": "<brief explanation>"}
                            ],
                            "summary": "<2-3 sentence overall assessment>"
                        }
                        PROMPT,
                ],
            ],
            'max_tokens'      => 1200,
            'temperature'     => 0.1,
            'response_format' => ['type' => 'json_object'],
        ]);

        return json_decode($response->choices[0]->message->content ?? '{}', true) ?? [
            'score'         => 0.5,
            'label'         => 'neutral',
            'breakdown'     => [],
            'emotional_arc' => [],
            'key_moments'   => [],
            'summary'       => 'Analysis unavailable.',
        ];
    }
}
