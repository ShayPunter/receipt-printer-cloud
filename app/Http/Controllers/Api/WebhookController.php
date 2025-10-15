<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\GroqService;
use App\Services\DeduplicationService;
use App\Services\SlackConversationBuffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WebhookController extends Controller
{
    public function __construct(
        private GroqService $groqService,
        private DeduplicationService $deduplicationService,
        private SlackConversationBuffer $slackBuffer
    ) {}

    /**
     * Receive webhook data and store message.
     *
     * POST /api/webhook
     * Body: { "source": "gmail", "body": "Message content..." }
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Store the message
            $message = Message::create([
                'source' => $request->input('source'),
                'body' => $request->input('body'),
                'processed' => false,
            ]);

            Log::info('Webhook message received', [
                'message_id' => $message->id,
                'source' => $message->source
            ]);

            // Dispatch job to process message asynchronously (optional)
            // ProcessMessageJob::dispatch($message);

            return response()->json([
                'success' => true,
                'message' => 'Message received and queued for processing',
                'data' => [
                    'message_id' => $message->id,
                    'source' => $message->source,
                    'processed' => $message->processed,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to store webhook message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process webhook'
            ], 500);
        }
    }

    /**
     * Process a message and extract action items.
     *
     * POST /api/webhook/process/{id}
     */
    public function process(int $id): JsonResponse
    {
        try {
            $message = Message::findOrFail($id);

            if ($message->processed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Message already processed',
                    'data' => [
                        'message_id' => $message->id,
                        'action_items_count' => $message->actionItems()->count()
                    ]
                ], 400);
            }

            // Extract action items using Groq
            $actionItems = $this->groqService->extractActionItems(
                $message->body,
                $message->source
            );

            // Store action items with metadata and check for duplicates
            $createdCount = 0;
            $duplicateCount = 0;
            $duplicateDetails = [];

            foreach ($actionItems as $item) {
                // Check if this action item is a duplicate
                $duplicationCheck = $this->deduplicationService->isDuplicate(
                    $item['action'],
                    $item['priority'],
                    $item['sender'] ?? null
                );

                if ($duplicationCheck['is_duplicate']) {
                    $duplicateCount++;
                    $duplicateDetails[] = [
                        'action' => $item['action'],
                        'duplicate_of' => $duplicationCheck['duplicate_of']?->action,
                        'reasoning' => $duplicationCheck['reasoning']
                    ];

                    Log::info('Duplicate action item detected, skipping', [
                        'message_id' => $message->id,
                        'action' => $item['action'],
                        'duplicate_of' => $duplicationCheck['duplicate_of']?->id,
                        'reasoning' => $duplicationCheck['reasoning']
                    ]);

                    continue; // Skip creating this duplicate action item
                }

                // Not a duplicate, create the action item
                $actionItem = $message->actionItems()->create([
                    'source' => $message->source,
                    'action' => $item['action'],
                    'priority' => $item['priority'],
                    'sender' => $item['sender'] ?? null,
                    'synced' => false,
                ]);

                // Store metadata if provided
                if (isset($item['reasoning']) || isset($item['confidence']) || isset($item['relevance_score'])) {
                    $actionItem->metadata()->create([
                        'reasoning' => $item['reasoning'] ?? null,
                        'confidence' => $item['confidence'] ?? null,
                        'relevance_score' => $item['relevance_score'] ?? null,
                    ]);
                }

                $createdCount++;
            }

            // Mark message as processed
            $message->update(['processed' => true]);

            Log::info('Message processed', [
                'message_id' => $message->id,
                'action_items_extracted' => count($actionItems),
                'action_items_created' => $createdCount,
                'duplicates_skipped' => $duplicateCount
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Message processed successfully',
                'data' => [
                    'message_id' => $message->id,
                    'action_items_extracted' => count($actionItems),
                    'action_items_created' => $createdCount,
                    'duplicates_skipped' => $duplicateCount,
                    'duplicate_details' => $duplicateDetails,
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Failed to process message', [
                'message_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process message'
            ], 500);
        }
    }

    /**
     * Receive webhook and process immediately in one call.
     *
     * POST /api/webhook/process-immediately
     * Body: { "source": "gmail", "body": "Message content..." }
     */
    public function storeAndProcess(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Store the message
            $message = Message::create([
                'source' => $request->input('source'),
                'body' => $request->input('body'),
                'processed' => false,
            ]);

            // Extract action items using Groq
            $actionItems = $this->groqService->extractActionItems(
                $message->body,
                $message->source
            );

            // Store action items with metadata and check for duplicates
            $createdCount = 0;
            $duplicateCount = 0;
            $duplicateDetails = [];

            foreach ($actionItems as $item) {
                // Check if this action item is a duplicate
                $duplicationCheck = $this->deduplicationService->isDuplicate(
                    $item['action'],
                    $item['priority'],
                    $item['sender'] ?? null
                );

                if ($duplicationCheck['is_duplicate']) {
                    $duplicateCount++;
                    $duplicateDetails[] = [
                        'action' => $item['action'],
                        'duplicate_of' => $duplicationCheck['duplicate_of']?->action,
                        'reasoning' => $duplicationCheck['reasoning']
                    ];

                    Log::info('Duplicate action item detected, skipping', [
                        'message_id' => $message->id,
                        'action' => $item['action'],
                        'duplicate_of' => $duplicationCheck['duplicate_of']?->id,
                        'reasoning' => $duplicationCheck['reasoning']
                    ]);

                    continue; // Skip creating this duplicate action item
                }

                // Not a duplicate, create the action item
                $actionItem = $message->actionItems()->create([
                    'source' => $message->source,
                    'action' => $item['action'],
                    'priority' => $item['priority'],
                    'sender' => $item['sender'] ?? null,
                    'synced' => false,
                ]);

                // Store metadata if provided
                if (isset($item['reasoning']) || isset($item['confidence']) || isset($item['relevance_score'])) {
                    $actionItem->metadata()->create([
                        'reasoning' => $item['reasoning'] ?? null,
                        'confidence' => $item['confidence'] ?? null,
                        'relevance_score' => $item['relevance_score'] ?? null,
                    ]);
                }

                $createdCount++;
            }

            // Mark message as processed
            $message->update(['processed' => true]);

            Log::info('Webhook message received and processed', [
                'message_id' => $message->id,
                'source' => $message->source,
                'action_items_extracted' => count($actionItems),
                'action_items_created' => $createdCount,
                'duplicates_skipped' => $duplicateCount
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Message received and processed successfully',
                'data' => [
                    'message_id' => $message->id,
                    'source' => $message->source,
                    'action_items_extracted' => count($actionItems),
                    'action_items_created' => $createdCount,
                    'duplicates_skipped' => $duplicateCount,
                    'duplicate_details' => $duplicateDetails,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to store and process webhook message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process webhook'
            ], 500);
        }
    }

    /**
     * Receive Slack message and add to conversation buffer.
     *
     * POST /api/webhook/slack
     * Body: {
     *   "channel": "C1234567",
     *   "thread_ts": "1234567890.123456" (optional),
     *   "body": "Message content",
     *   "sender": "User Name",
     *   "timestamp": "2025-01-13T10:30:00Z" (optional)
     * }
     */
    public function slack(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'channel' => 'required|string|max:255',
            'thread_ts' => 'nullable|string|max:255',
            'body' => 'required|string',
            'sender' => 'required|string|max:255',
            'timestamp' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Add message to conversation buffer
            $conversation = $this->slackBuffer->addMessage(
                $request->input('channel'),
                $request->input('thread_ts'),
                $request->input('body'),
                $request->input('sender'),
                $request->input('timestamp')
            );

            // Check if conversation should be processed immediately
            $shouldProcessNow = $this->slackBuffer->shouldProcessImmediately($conversation);

            if ($shouldProcessNow) {
                // Process conversation immediately
                $message = $this->slackBuffer->markAsProcessed($conversation);

                // Extract action items
                $actionItems = $this->groqService->extractActionItems(
                    $message->body,
                    'slack'
                );

                // Store action items with deduplication
                $createdCount = 0;
                $duplicateCount = 0;

                foreach ($actionItems as $item) {
                    $duplicationCheck = $this->deduplicationService->isDuplicate(
                        $item['action'],
                        $item['priority'],
                        $item['sender'] ?? null
                    );

                    if ($duplicationCheck['is_duplicate']) {
                        $duplicateCount++;
                        continue;
                    }

                    $actionItem = $message->actionItems()->create([
                        'source' => 'slack',
                        'action' => $item['action'],
                        'priority' => $item['priority'],
                        'sender' => $item['sender'] ?? null,
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
                }

                $message->update(['processed' => true]);

                Log::info('Slack conversation processed immediately', [
                    'conversation_key' => $conversation->conversation_key,
                    'message_count' => count($conversation->messages),
                    'action_items_created' => $createdCount,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Slack message buffered and processed immediately',
                    'data' => [
                        'conversation_key' => $conversation->conversation_key,
                        'message_count' => count($conversation->messages),
                        'processed_immediately' => true,
                        'action_items_created' => $createdCount,
                        'duplicates_skipped' => $duplicateCount,
                    ]
                ], 201);
            }

            // Message buffered, will be processed later
            Log::info('Slack message buffered', [
                'conversation_key' => $conversation->conversation_key,
                'message_count' => count($conversation->messages),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Slack message buffered successfully',
                'data' => [
                    'conversation_key' => $conversation->conversation_key,
                    'message_count' => count($conversation->messages),
                    'processed_immediately' => false,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to process Slack webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process Slack webhook'
            ], 500);
        }
    }
}
