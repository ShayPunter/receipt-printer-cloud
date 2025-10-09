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
     * @return array Array of action items with priority levels and sender
     *               Each item: ['action' => string, 'priority' => 'low'|'medium'|'high', 'sender' => string|null]
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
                        'content' => 'You are an AI assistant that extracts actionable tasks from messages. Return ONLY a valid JSON array, nothing else - no explanations, no markdown, no text before or after. The array must contain objects with "action", "priority", and "sender" fields. Priority must be "low", "medium", or "high". Sender should be name or email from the message, or null if not found. Example: [{"action":"Task","priority":"high","sender":"Name"}]'
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
Analyze the following message from {$source} and extract ALL actionable items with their priority levels.

An actionable item is something that requires action, such as:
- Tasks to complete
- Items to review
- Things to respond to
- Meetings to attend
- Decisions to make
- Information to provide

Priority levels:
- HIGH: Urgent, time-sensitive, critical, important deadlines, requests from superiors
- MEDIUM: Normal priority, routine tasks, no immediate deadline
- LOW: Optional, nice-to-have, informational, can be deferred

Message:
{$messageBody}

IMPORTANT: Return ONLY the JSON array, no explanations or extra text.

Format:
[
  {"action": "Complete the project report by Friday", "priority": "high", "sender": "Sarah Johnson"},
  {"action": "Reply to John's email about the meeting", "priority": "medium", "sender": "John Smith"}
]

Sender extraction:
- Extract from "From:", sender name, or email address
- Use null if sender cannot be determined

Return empty array [] if no actionable items exist.
PROMPT;
    }

    /**
     * Parse the AI response and extract action items with priority.
     */
    private function parseActionItems(string $content): array
    {
        // Clean up the response
        $content = trim($content);

        // Remove markdown code blocks if present
        $content = preg_replace('/^```json?\s*/m', '', $content);
        $content = preg_replace('/\s*```$/m', '', $content);
        $content = trim($content);

        // Extract JSON array from response (handle cases where AI adds explanatory text)
        // Look for array pattern [ ... ] and extract it
        if (preg_match('/\[(?:[^[\]]|(?R))*\]/s', $content, $matches)) {
            $content = $matches[0];
        }

        try {
            $items = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($items)) {
                Log::warning('Groq returned non-array response', ['content' => $content]);
                return [];
            }

            // Parse and validate each item
            $validItems = [];
            foreach ($items as $item) {
                // Handle both new format (object) and legacy format (string)
                if (is_array($item) && isset($item['action'])) {
                    $action = trim($item['action']);
                    $priority = strtolower($item['priority'] ?? 'medium');
                    $sender = isset($item['sender']) && !empty($item['sender']) ? trim($item['sender']) : null;

                    // Validate priority
                    if (!in_array($priority, ['low', 'medium', 'high'])) {
                        $priority = 'medium';
                    }

                    if (!empty($action)) {
                        $validItems[] = [
                            'action' => $action,
                            'priority' => $priority,
                            'sender' => $sender,
                        ];
                    }
                } elseif (is_string($item)) {
                    // Legacy format support
                    $action = trim($item);
                    if (!empty($action)) {
                        $validItems[] = [
                            'action' => $action,
                            'priority' => 'medium',
                            'sender' => null,
                        ];
                    }
                }
            }

            return $validItems;

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
