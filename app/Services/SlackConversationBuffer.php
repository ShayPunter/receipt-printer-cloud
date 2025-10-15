<?php

namespace App\Services;

use App\Models\SlackConversation;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SlackConversationBuffer
{
    // Time to wait before processing a conversation (in minutes)
    private int $bufferTimeMinutes;

    // Minimum silence time to trigger early processing (in minutes)
    private int $silenceThresholdMinutes;

    public function __construct()
    {
        $this->bufferTimeMinutes = (int) config('services.slack.buffer_time', 5);
        $this->silenceThresholdMinutes = (int) config('services.slack.silence_threshold', 3);
    }

    /**
     * Add a Slack message to the conversation buffer.
     *
     * @param string $channel Channel ID
     * @param string|null $threadTs Thread timestamp (null for non-threaded)
     * @param string $messageBody Message content
     * @param string $sender Sender name/ID
     * @param string|null $timestamp Message timestamp
     * @return SlackConversation
     */
    public function addMessage(
        string $channel,
        ?string $threadTs,
        string $messageBody,
        string $sender,
        ?string $timestamp = null
    ): SlackConversation {
        // Generate conversation key (channel + thread)
        $conversationKey = $this->generateConversationKey($channel, $threadTs);

        // Find or create conversation
        $conversation = SlackConversation::firstOrCreate(
            [
                'conversation_key' => $conversationKey,
                'processed' => false,
            ],
            [
                'channel' => $channel,
                'thread_ts' => $threadTs,
                'messages' => [],
                'first_message_at' => now(),
                'last_message_at' => now(),
            ]
        );

        // Append message to conversation
        $messages = $conversation->messages ?? [];
        $messages[] = [
            'sender' => $sender,
            'body' => $messageBody,
            'timestamp' => $timestamp ?? now()->toIso8601String(),
        ];

        $conversation->messages = $messages;
        $conversation->last_message_at = now();
        $conversation->save();

        Log::info('Slack message added to buffer', [
            'conversation_key' => $conversationKey,
            'message_count' => count($messages),
            'buffer_age_seconds' => $conversation->first_message_at->diffInSeconds(now()),
        ]);

        return $conversation;
    }

    /**
     * Check if conversation should be processed immediately.
     *
     * @param SlackConversation $conversation
     * @return bool
     */
    public function shouldProcessImmediately(SlackConversation $conversation): bool
    {
        $messages = $conversation->messages ?? [];

        // Must have at least 2 messages for context
        if (count($messages) < 2) {
            return false;
        }

        $lastMessage = end($messages);
        $lastMessageBody = strtolower($lastMessage['body'] ?? '');

        // Check for strong action indicators
        $actionIndicators = [
            'can you',
            'could you',
            'please ',
            'need to',
            'make sure',
            'don\'t forget',
            'remember to',
            'urgent',
            'asap',
            'by tomorrow',
            'by today',
            'deadline',
        ];

        foreach ($actionIndicators as $indicator) {
            if (str_contains($lastMessageBody, $indicator)) {
                Log::info('Conversation contains action indicator, processing immediately', [
                    'conversation_key' => $conversation->conversation_key,
                    'indicator' => $indicator,
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Get conversations ready for processing.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getReadyConversations()
    {
        $bufferCutoff = Carbon::now()->subMinutes($this->bufferTimeMinutes);

        return SlackConversation::where('processed', false)
            ->where(function ($query) use ($bufferCutoff) {
                // Either buffer time has elapsed
                $query->where('first_message_at', '<=', $bufferCutoff)
                    // Or conversation has been silent for threshold time
                    ->orWhere('last_message_at', '<=', Carbon::now()->subMinutes($this->silenceThresholdMinutes));
            })
            ->orderBy('first_message_at')
            ->get();
    }

    /**
     * Format conversation messages for AI processing.
     *
     * @param SlackConversation $conversation
     * @return string
     */
    public function formatConversationForAI(SlackConversation $conversation): string
    {
        $messages = $conversation->messages ?? [];

        if (empty($messages)) {
            return '';
        }

        $formatted = "=== Slack Conversation ===\n";
        $formatted .= "Channel: {$conversation->channel}\n";

        if ($conversation->thread_ts) {
            $formatted .= "Thread: {$conversation->thread_ts}\n";
        }

        $formatted .= "Duration: " . $conversation->first_message_at->diffForHumans($conversation->last_message_at, true) . "\n";
        $formatted .= "Messages: " . count($messages) . "\n\n";

        foreach ($messages as $index => $message) {
            $sender = $message['sender'] ?? 'Unknown';
            $body = $message['body'] ?? '';
            $timestamp = $message['timestamp'] ?? '';

            $formatted .= "[" . ($index + 1) . "] {$sender}: {$body}\n\n";
        }

        return $formatted;
    }

    /**
     * Generate conversation key from channel and thread.
     */
    private function generateConversationKey(string $channel, ?string $threadTs): string
    {
        if ($threadTs) {
            return "{$channel}:{$threadTs}";
        }

        // For non-threaded messages, group by channel and time window (5 min)
        $timeWindow = floor(time() / 300); // 5-minute buckets
        return "{$channel}:general:{$timeWindow}";
    }

    /**
     * Mark conversation as processed and create Message record.
     *
     * @param SlackConversation $conversation
     * @return Message
     */
    public function markAsProcessed(SlackConversation $conversation): Message
    {
        // Create a Message record with the full conversation
        $message = Message::create([
            'source' => 'slack',
            'body' => $this->formatConversationForAI($conversation),
            'processed' => false, // Will be processed by webhook controller
        ]);

        // Mark conversation as processed
        $conversation->processed = true;
        $conversation->processed_at = now();
        $conversation->message_id = $message->id;
        $conversation->save();

        return $message;
    }

    /**
     * Clean up old processed conversations (older than 7 days).
     */
    public function cleanupOldConversations(): int
    {
        $cutoff = Carbon::now()->subDays(7);

        return SlackConversation::where('processed', true)
            ->where('processed_at', '<=', $cutoff)
            ->delete();
    }
}
