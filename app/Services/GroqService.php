<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService
{
    private string $apiKey;
    private string $apiUrl = 'https://api.groq.com/openai/v1/chat/completions';
    private string $model = 'meta-llama/llama-4-scout-17b-16e-instruct'; // Llama 4 Scout with 128k context

    public function __construct()
    {
        $this->apiKey = config('services.groq.api_key');
    }

    /**
     * Extract actionable items from message body using Groq AI.
     *
     * @param string $messageBody
     * @param string $source
     * @return array Array of action items (strings)
     */
    public function extractActionItems(string $messageBody, string $source): array
    {
        if (empty($this->apiKey)) {
            Log::error('Groq API key not configured');
            return [];
        }

        try {
            $prompt = $this->buildPrompt($messageBody, $source);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout(30)
            ->post($this->apiUrl, [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an AI assistant that extracts actionable tasks from messages. Return ONLY a JSON array of action items, nothing else. Each item should be a clear, concise action. If no actions are found, return an empty array [].'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 1000,
            ]);

            if (!$response->successful()) {
                Log::error('Groq API error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return [];
            }

            $result = $response->json();
            $content = $result['choices'][0]['message']['content'] ?? '';

            return $this->parseActionItems($content);

        } catch (\Exception $e) {
            Log::error('Groq API exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Build the prompt for Groq AI.
     */
    private function buildPrompt(string $messageBody, string $source): string
    {
        return <<<PROMPT
Analyze the following message from {$source} and extract ALL actionable items.

An actionable item is something that requires action, such as:
- Tasks to complete
- Items to review
- Things to respond to
- Meetings to attend
- Decisions to make
- Information to provide

Message:
{$messageBody}

Return a JSON array of clear, concise action items. Example format:
["Complete the project report by Friday", "Reply to John's email about the meeting", "Review the pull request"]

If there are no actionable items, return an empty array: []
PROMPT;
    }

    /**
     * Parse the AI response and extract action items.
     */
    private function parseActionItems(string $content): array
    {
        // Clean up the response
        $content = trim($content);

        // Remove markdown code blocks if present
        $content = preg_replace('/^```json?\s*/m', '', $content);
        $content = preg_replace('/\s*```$/m', '', $content);
        $content = trim($content);

        try {
            $items = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($items)) {
                Log::warning('Groq returned non-array response', ['content' => $content]);
                return [];
            }

            // Filter out empty items and ensure strings
            return array_values(array_filter(array_map(function ($item) {
                return is_string($item) ? trim($item) : '';
            }, $items)));

        } catch (\JsonException $e) {
            Log::error('Failed to parse Groq response as JSON', [
                'content' => $content,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Test the Groq API connection.
     */
    public function testConnection(): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])
            ->timeout(10)
            ->get('https://api.groq.com/openai/v1/models');

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Groq connection test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
