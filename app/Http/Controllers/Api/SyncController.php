<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SyncController extends Controller
{
    /**
     * Get unsynced action items for external app to sync.
     *
     * GET /api/sync
     * Optional query params:
     * - source: filter by source (e.g., ?source=gmail)
     * - priority: filter by priority (e.g., ?priority=high)
     * - limit: limit number of results (default: 100)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ActionItem::with('message')->unsynced();

            // Filter by source if provided
            if ($request->has('source')) {
                $query->fromSource($request->input('source'));
            }

            // Filter by priority if provided
            if ($request->has('priority')) {
                $query->byPriority($request->input('priority'));
            }

            // Limit results
            $limit = min((int) $request->input('limit', 100), 500);
            $actionItems = $query->orderBy('created_at', 'asc')
                               ->limit($limit)
                               ->get();

            $data = $actionItems->map(function ($item) {
                return [
                    'id' => $item->id,
                    'action' => $item->action,
                    'priority' => $item->priority ?? 'medium',
                    'sender' => $item->sender,
                    'source' => $item->source,
                    'message_id' => $item->message_id,
                    'message_body' => $item->message->body ?? null,
                    'created_at' => $item->created_at->toISOString(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'count' => $data->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch unsynced action items', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch action items'
            ], 500);
        }
    }

    /**
     * Mark action items as synced.
     *
     * POST /api/sync/mark-synced
     * Body: { "action_item_ids": [1, 2, 3] }
     */
    public function markSynced(Request $request): JsonResponse
    {
        $request->validate([
            'action_item_ids' => 'required|array',
            'action_item_ids.*' => 'integer|exists:action_items,id'
        ]);

        try {
            $ids = $request->input('action_item_ids');

            $updated = ActionItem::whereIn('id', $ids)
                ->unsynced()
                ->update([
                    'synced' => true,
                    'synced_at' => now(),
                ]);

            Log::info('Action items marked as synced', [
                'count' => $updated,
                'ids' => $ids
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Action items marked as synced',
                'data' => [
                    'updated_count' => $updated,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to mark action items as synced', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark action items as synced'
            ], 500);
        }
    }

    /**
     * Get sync statistics.
     *
     * GET /api/sync/stats
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = [
                'total_action_items' => ActionItem::count(),
                'unsynced_count' => ActionItem::unsynced()->count(),
                'synced_count' => ActionItem::synced()->count(),
                'by_source' => ActionItem::selectRaw('source, COUNT(*) as count, SUM(CASE WHEN synced = 1 THEN 1 ELSE 0 END) as synced_count')
                    ->groupBy('source')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'source' => $item->source,
                            'total' => $item->count,
                            'synced' => $item->synced_count,
                            'unsynced' => $item->count - $item->synced_count,
                        ];
                    }),
                'by_priority' => ActionItem::selectRaw('priority, COUNT(*) as count, SUM(CASE WHEN synced = 1 THEN 1 ELSE 0 END) as synced_count')
                    ->groupBy('priority')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'priority' => $item->priority,
                            'total' => $item->count,
                            'synced' => $item->synced_count,
                            'unsynced' => $item->count - $item->synced_count,
                        ];
                    }),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch sync stats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch sync stats'
            ], 500);
        }
    }
}
