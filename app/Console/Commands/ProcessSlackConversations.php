<?php

namespace App\Console\Commands;

use App\Services\SlackConversationBuffer;
use App\Services\GroqService;
use App\Services\DeduplicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessSlackConversations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'slack:process-conversations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process buffered Slack conversations and extract action items';

    /**
     * Execute the console command.
     */
    public function handle(
        SlackConversationBuffer $slackBuffer,
        GroqService $groqService,
        DeduplicationService $deduplicationService
    ): int {
        $this->info('Processing buffered Slack conversations...');

        // Get conversations ready for processing
        $conversations = $slackBuffer->getReadyConversations();

        if ($conversations->isEmpty()) {
            $this->info('No conversations ready for processing.');
            return Command::SUCCESS;
        }

        $this->info("Found {$conversations->count()} conversation(s) to process.");

        $totalActionsCreated = 0;
        $totalDuplicatesSkipped = 0;

        foreach ($conversations as $conversation) {
            try {
                $this->line("Processing conversation: {$conversation->conversation_key}");

                // Mark as processed and create Message record
                $message = $slackBuffer->markAsProcessed($conversation);

                // Extract action items with full context
                $actionItems = $groqService->extractActionItems(
                    $message->body,
                    'slack'
                );

                $this->line("  Extracted " . count($actionItems) . " action item(s)");

                // Store action items with deduplication
                $createdCount = 0;
                $duplicateCount = 0;

                foreach ($actionItems as $item) {
                    $environment = $item['environment'] ?? null;
                    $isProduction = $environment === 'production';

                    // Skip deduplication check for production items - they ALWAYS print
                    if (!$isProduction) {
                        $duplicationCheck = $deduplicationService->isDuplicate(
                            $item['action'],
                            $item['priority'],
                            $item['sender'] ?? null
                        );

                        if ($duplicationCheck['is_duplicate']) {
                            $duplicateCount++;
                            $this->line("    [SKIP] Duplicate: {$item['action']}");
                            continue;
                        }
                    } else {
                        $this->line("    [PRODUCTION] Bypassing deduplication");
                    }

                    $actionItem = $message->actionItems()->create([
                        'source' => 'slack',
                        'action' => $item['action'],
                        'priority' => $item['priority'],
                        'sender' => $item['sender'] ?? null,
                        'environment' => $environment,
                        'synced' => false,
                    ]);

                    if (isset($item['reasoning']) || isset($item['confidence']) || isset($item['relevance_score'])) {
                        $actionItem->metadata()->create([
                            'reasoning' => $item['reasoning'] ?? null,
                            'confidence' => $item['confidence'] ?? null,
                            'relevance_score' => $item['relevance_score'] ?? null,
                        ]);
                    }

                    $createdCount++;
                    $envLabel = $environment ? " [" . strtoupper($environment) . "]" : "";
                    $this->line("    [NEW]{$envLabel} {$item['action']} (Priority: {$item['priority']})");
                }

                $message->update(['processed' => true]);

                $totalActionsCreated += $createdCount;
                $totalDuplicatesSkipped += $duplicateCount;

                $this->line("  Created: {$createdCount}, Skipped: {$duplicateCount}");

                Log::info('✓ SLACK CONVERSATION PROCESSED (Scheduled)', [
                    'conversation_key' => $conversation->conversation_key,
                    'channel' => $conversation->channel,
                    'thread_ts' => $conversation->thread_ts,
                    'message_count' => count($conversation->messages),
                    'full_conversation' => $message->body,
                    'action_items_extracted' => count($actionItems),
                    'action_items_created' => $createdCount,
                    'duplicates_skipped' => $duplicateCount,
                    'processed_immediately' => false,
                    'buffer_age_minutes' => $conversation->first_message_at->diffInMinutes(now()),
                ]);

            } catch (\Exception $e) {
                $this->error("Failed to process conversation {$conversation->conversation_key}: {$e->getMessage()}");

                Log::error('Failed to process Slack conversation', [
                    'conversation_key' => $conversation->conversation_key,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        // Clean up old processed conversations
        $deleted = $slackBuffer->cleanupOldConversations();
        if ($deleted > 0) {
            $this->line("Cleaned up {$deleted} old conversation(s)");
        }

        $this->info("Processing complete!");
        $this->info("Total actions created: {$totalActionsCreated}");
        $this->info("Total duplicates skipped: {$totalDuplicatesSkipped}");

        return Command::SUCCESS;
    }
}
