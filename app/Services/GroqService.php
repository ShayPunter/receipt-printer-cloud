<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService
{
    private string $apiKey;
    private ?string $userJobContext;
    private string $apiUrl = 'https://api.groq.com/openai/v1/chat/completions';
    private string $model = 'meta-llama/llama-4-scout-17b-16e-instruct'; // Llama 4 Scout with 128k context

    public function __construct()
    {
        $this->apiKey = config('services.groq.api_key');
        $this->userJobContext = config('services.groq.user_job_context');
    }

    /**
     * Extract actionable items from message body using Groq AI.
     *
     * @param string $messageBody
     * @param string $source
     * @return array Array of action items with priority, sender, reasoning, confidence, and relevance_score
     *               Each item: ['action' => string, 'priority' => string, 'sender' => string|null,
     *                           'reasoning' => string|null, 'confidence' => float|null, 'relevance_score' => float|null]
     */
    public function extractActionItems(string $messageBody, string $source): array
    {
        if (empty($this->apiKey)) {
            Log::error('Groq API key not configured');
            return [];
        }

        try {
            // Pre-filter URLs from message body to reduce noise and token usage
            $cleanedBody = $this->stripUrls($messageBody);

            $prompt = $this->buildPrompt($cleanedBody, $source);

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
                        'content' => 'You are an AI assistant that extracts actionable tasks from messages. Return ONLY a valid JSON array, nothing else - no explanations, no markdown, no text before or after. Each object must have: "action" (string), "priority" ("low"/"medium"/"high"), "sender" (name/email or null), "reasoning" (why this is actionable), "confidence" (0.0-1.0 how confident you are), "relevance_score" (0.0-1.0 how relevant to user\'s job). Example: [{"action":"Task","priority":"high","sender":"Name","reasoning":"Urgent deadline mentioned","confidence":0.95,"relevance_score":0.90}]'
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
     * Strip URLs from message body to reduce noise and token usage.
     * Removes http/https URLs including long tracking URLs.
     */
    private function stripUrls(string $messageBody): string
    {
        // Match http/https URLs including long tracking URLs with query parameters
        $pattern = '/https?:\/\/[^\s\]]+/i';

        // Replace URLs with empty string
        $cleaned = preg_replace($pattern, '', $messageBody);

        // Clean up multiple spaces and line breaks left by URL removal
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned);

        return $cleaned;
    }

    /**
     * Build the prompt for Groq AI.
     */
    private function buildPrompt(string $messageBody, string $source): string
    {
        $relevanceSection = '';

        if ($this->userJobContext) {
            $relevanceSection = "

USER JOB CONTEXT:
{$this->userJobContext}

RELEVANCE SCORING:
Evaluate how relevant each action item is to the user's job responsibilities (0.0-1.0):
- 1.0: Directly related to core responsibilities (architecture, team management, deployment, monitoring, QA)
- 0.7-0.9: Related to technical areas they oversee or should be aware of
- 0.4-0.6: Tangentially related or could impact their work indirectly
- 0.0-0.3: Not directly relevant to their role (marketing, sales, other teams' work)

Consider:
- Is this within their area of responsibility?
- Does it require their decision-making or input?
- Does it impact systems/teams they manage?
- Is this something they should delegate vs. handle personally?
- If it something that they should delegate, print a ticket with delegation word on it.
";
        }

        return <<<PROMPT
Analyze the following message from {$source} and extract actionable items.
{$relevanceSection}

CRITICAL RULES:
1. IGNORE newsletters, marketing emails, promotional content, and automated notifications
2. IGNORE informational updates that don't require action (status reports, announcements, surveys, reports)
3. IGNORE invitations to webinars, livestreams, events unless DIRECTLY requested by the recipient
4. IGNORE general "FYI" content, industry updates, blog announcements, or content recommendations
5. Only extract DISTINCT action items - do NOT create multiple items for the same task
6. If multiple sentences describe the same problem/task, consolidate into ONE action item
7. Ignore email signatures, disclaimers, and formatting text
8. Only extract items that require a specific action from the RECIPIENT (not optional promotional activities)

An actionable item is something that requires action:
- Tasks to complete
- Items to review
- Things to respond to
- Meetings to attend
- Decisions to make
- Problems to fix

NOT actionable (ignore these):
- Newsletters and marketing emails (even if they invite you to events/webinars)
- Livestream announcements, webinar invitations, event promotions
- Industry reports, surveys, or content recommendations
- "Join us for..." or "Watch our..." promotional invitations
- Automated notifications (e.g., "Your order has shipped")
- General announcements without specific requests
- Status updates that are purely informational
- Promotional content and advertisements
- Subscription confirmations and receipts
- API updates, product announcements, or feature releases (unless you specifically requested them)

Key indicators of NON-actionable content:
- Contains phrases like "Join us", "Watch on YouTube", "Register now", "Download the report"
- Talks about upcoming events, webinars, or livestreams as promotional content
- Includes statistics, industry trends, or market research
- Has a marketing/promotional tone rather than direct work requests
- Contains incentives like "account credit", "early access", or promotional offers

Priority levels:
- HIGH: Urgent, critical, system failures, explicit deadlines, requests from superiors
- MEDIUM: Normal priority, routine tasks, no immediate deadline
- LOW: Optional, nice-to-have, informational, can be deferred

EXTRACTING DETAILED, SPECIFIC ACTION ITEMS:
Action descriptions must be DETAILED and SPECIFIC with enough context to understand the task without reading the full message.

Include relevant details such as:
- For errors: error type, environment, affected component, key error message
- For requests: what specifically needs to be done, where, and any constraints
- For meetings: topic, participants, or purpose if mentioned
- For reviews: what needs reviewing and any specific aspects to focus on
- For deadlines: the date/time if specified

BAD Examples (too vague):
❌ "Investigate and resolve the fatal error on the UAT environment"
❌ "Fix the database issue"
❌ "Update the documentation"
❌ "Review the pull request"
❌ "Follow up with the client"

GOOD Examples (detailed and specific):
✅ "Fix Unauthenticated error in xwave-app UAT: minified:aua throwing 'Unauthorised: message: Unauthenticated' at ApiProvider.put in /admin/organisation_settings"
✅ "Fix database connection timeout in production: MySQL pool exhausted during peak hours (12-2pm) affecting checkout flow"
✅ "Update API documentation for /auth/login endpoint to include new 2FA flow and error codes 401/403"
✅ "Review PR #847: Refactoring user authentication service - focus on session management changes"
✅ "Follow up with Acme Corp client about delayed payment for Invoice #INV-2024-0523 ($15,000 overdue by 14 days)"

Message:
{$messageBody}

IMPORTANT: Return ONLY the JSON array, no explanations or extra text.

Format (all fields required):
[
  {
    "action": "Fix Unauthenticated error in xwave-app UAT: minified:aua throwing 'Unauthorised: message: Unauthenticated' at ApiProvider.put in /admin/organisation_settings",
    "priority": "high",
    "sender": "Sentry",
    "reasoning": "Fatal authentication error blocking UAT admin functionality",
    "confidence": 0.95,
    "relevance_score": 0.95
  }
]

Field requirements:
- action: DETAILED, SPECIFIC description with relevant context. Extract key details from the message that make the task clear and actionable. Someone reading just this description should understand what needs to be done.
- priority: Based on urgency and impact (fatal errors, deadlines = high priority)
- sender: Extract from "From:", signature, email, or system name (e.g., "Sentry", "Jira"). Use null if unclear.
- reasoning: Explain WHY this is actionable (1-2 sentences)
- confidence: 0.0-1.0 (certainty this is a real, distinct action item)
- relevance_score: 0.0-1.0 (how relevant to user's job responsibilities). Set to 1.0 if no job context provided.

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
                    $reasoning = isset($item['reasoning']) && !empty($item['reasoning']) ? trim($item['reasoning']) : null;
                    $confidence = isset($item['confidence']) ? (float) $item['confidence'] : null;
                    $relevanceScore = isset($item['relevance_score']) ? (float) $item['relevance_score'] : null;

                    // Validate priority
                    if (!in_array($priority, ['low', 'medium', 'high'])) {
                        $priority = 'medium';
                    }

                    // Validate confidence (0.0 to 1.0)
                    if ($confidence !== null && ($confidence < 0.0 || $confidence > 1.0)) {
                        $confidence = null;
                    }

                    // Validate relevance_score (0.0 to 1.0)
                    if ($relevanceScore !== null && ($relevanceScore < 0.0 || $relevanceScore > 1.0)) {
                        $relevanceScore = null;
                    }

                    if (!empty($action)) {
                        $validItems[] = [
                            'action' => $action,
                            'priority' => $priority,
                            'sender' => $sender,
                            'reasoning' => $reasoning,
                            'confidence' => $confidence,
                            'relevance_score' => $relevanceScore,
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
                            'reasoning' => null,
                            'confidence' => null,
                            'relevance_score' => null,
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
