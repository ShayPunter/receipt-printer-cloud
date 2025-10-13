<?php

namespace App\Services;

use App\Models\ActionItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DeduplicationService
{
    private string $apiKey;
    private string $apiUrl = 'https://api.groq.com/openai/v1/chat/completions';
    private string $model = 'meta-llama/llama-4-scout-17b-16e-instruct';

    public function __construct()
    {
        $this->apiKey = config('services.groq.api_key');
    }

    /**
     * Check if a new action item is a duplicate of recent tasks.
     *
     * @param string $newAction The new action description to check
     * @param string $priority Priority of the new action
     * @param string|null $sender Sender of the new action
     * @return array ['is_duplicate' => bool, 'duplicate_of' => ActionItem|null, 'reasoning' => string|null]
     */
    public function isDuplicate(string $newAction, string $priority = 'medium', ?string $sender = null): array
    {
        // Get all action items from last 48 hours that are not completed
        $recentTasks = $this->getRecentTasks();

        if ($recentTasks->isEmpty()) {
            return [
                'is_duplicate' => false,
                'duplicate_of' => null,
                'reasoning' => 'No recent tasks to compare against'
            ];
        }

        // Use Groq AI to check for duplicates
        $result = $this->checkDuplicateWithAI($newAction, $priority, $sender, $recentTasks);

        return $result;
    }

    /**
     * Get recent action items from last 48 hours.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getRecentTasks()
    {
        $cutoffTime = Carbon::now()->subHours(48);

        return ActionItem::where('created_at', '>=', $cutoffTime)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'action', 'priority', 'sender', 'created_at']);
    }

    /**
     * Use Groq AI to check if new action is duplicate of existing tasks.
     *
     * @param string $newAction
     * @param string $priority
     * @param string|null $sender
     * @param \Illuminate\Database\Eloquent\Collection $recentTasks
     * @return array
     */
    private function checkDuplicateWithAI(string $newAction, string $priority, ?string $sender, $recentTasks): array
    {
        if (empty($this->apiKey)) {
            Log::error('Groq API key not configured for deduplication');
            return [
                'is_duplicate' => false,
                'duplicate_of' => null,
                'reasoning' => 'API key not configured'
            ];
        }

        try {
            // Build the task list for comparison
            $taskList = $recentTasks->map(function ($task, $index) {
                $age = Carbon::parse($task->created_at)->diffForHumans();
                return sprintf(
                    "%d. [ID: %s] %s (Priority: %s, Sender: %s, Created: %s)",
                    $index + 1,
                    $task->id,
                    $task->action,
                    $task->priority,
                    $task->sender ?? 'unknown',
                    $age
                );
            })->join("\n");

            $prompt = $this->buildDeduplicationPrompt($newAction, $priority, $sender, $taskList);

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
                        'content' => 'You are an AI that detects duplicate tasks. Return ONLY a valid JSON object with: {"is_duplicate": boolean, "duplicate_id": "string or null", "reasoning": "string"}. No explanations, no markdown, just the JSON object.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.2, // Lower temperature for more consistent duplicate detection
                'max_tokens' => 300,
            ]);

            if (!$response->successful()) {
                Log::error('Groq API error in deduplication', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return [
                    'is_duplicate' => false,
                    'duplicate_of' => null,
                    'reasoning' => 'API error occurred'
                ];
            }

            $result = $response->json();
            $content = $result['choices'][0]['message']['content'] ?? '';

            return $this->parseDeduplicationResponse($content, $recentTasks);

        } catch (\Exception $e) {
            Log::error('Groq deduplication exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'is_duplicate' => false,
                'duplicate_of' => null,
                'reasoning' => 'Exception occurred: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Build the deduplication prompt for Groq AI.
     */
    private function buildDeduplicationPrompt(string $newAction, string $priority, ?string $sender, string $taskList): string
    {
        return <<<PROMPT
You are checking if a NEW action item is a duplicate of any EXISTING recent tasks (from the last 48 hours).

NEW ACTION ITEM TO CHECK:
Action: {$newAction}
Priority: {$priority}
Sender: {$sender}

EXISTING RECENT TASKS (last 48 hours):
{$taskList}

DUPLICATE DETECTION RULES:
1. Consider it a DUPLICATE if:
   - The core task/goal is the same (even if wording differs)
   - The error/issue being addressed is the same (same error, same environment)
   - It's clearly the same meeting, review, or request
   - The action would resolve the same problem as an existing task

2. Consider it NOT a duplicate if:
   - Different error types or environments (UAT vs Production)
   - Different components/features affected
   - Different deadlines or time-sensitive requests
   - Different meetings/events (even if same topic)
   - Similar but distinct tasks (e.g., "Fix login bug" vs "Fix signup bug")

3. Be strict about error matching:
   - "Fix Unauthenticated error in UAT" vs "Fix Unauthenticated error in Production" = NOT duplicates
   - "Fix database timeout in checkout" vs "Fix database timeout in login" = NOT duplicates
   - "Fix Unauthenticated error in UAT xwave-app" appearing twice = DUPLICATE

4. Time sensitivity matters:
   - If an old task exists but has been there for 24+ hours without resolution, a similar new urgent task may NOT be a duplicate (could be escalation)

RESPONSE FORMAT (JSON only):
{
  "is_duplicate": true,
  "duplicate_id": "12345678-1234-1234-1234-123456789abc",
  "reasoning": "This is the same Unauthenticated error in UAT xwave-app that was reported 6 hours ago"
}

OR if not a duplicate:
{
  "is_duplicate": false,
  "duplicate_id": null,
  "reasoning": "This is a different error (Production vs UAT) affecting different components"
}

Analyze the NEW action against all EXISTING tasks and return your assessment.
PROMPT;
    }

    /**
     * Parse the AI deduplication response.
     */
    private function parseDeduplicationResponse(string $content, $recentTasks): array
    {
        // Clean up the response
        $content = trim($content);

        // Remove markdown code blocks if present
        $content = preg_replace('/^```json?\s*/m', '', $content);
        $content = preg_replace('/\s*```$/m', '', $content);
        $content = trim($content);

        // Extract JSON object from response
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $content, $matches)) {
            $content = $matches[0];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            $isDuplicate = $data['is_duplicate'] ?? false;
            $duplicateId = $data['duplicate_id'] ?? null;
            $reasoning = $data['reasoning'] ?? 'No reasoning provided';

            // Find the duplicate task if ID was provided
            $duplicateTask = null;
            if ($isDuplicate && $duplicateId) {
                $duplicateTask = $recentTasks->firstWhere('id', $duplicateId);
            }

            return [
                'is_duplicate' => (bool) $isDuplicate,
                'duplicate_of' => $duplicateTask,
                'reasoning' => $reasoning
            ];

        } catch (\JsonException $e) {
            Log::error('Failed to parse deduplication response', [
                'content' => $content,
                'error' => $e->getMessage()
            ]);
            return [
                'is_duplicate' => false,
                'duplicate_of' => null,
                'reasoning' => 'Failed to parse AI response'
            ];
        }
    }
}
