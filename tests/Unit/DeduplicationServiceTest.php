<?php

use App\Models\ActionItem;
use App\Services\DeduplicationService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->deduplicationService = new DeduplicationService();
});

it('detects duplicate action items with same error in same environment', function () {
    // Create an existing action item
    $existingItem = ActionItem::create([
        'message_id' => 1,
        'source' => 'sentry',
        'action' => 'Fix Unauthenticated error in xwave-app UAT: minified:aua throwing Unauthorised',
        'priority' => 'high',
        'sender' => 'Sentry',
        'synced' => false,
    ]);

    // Mock Groq API response indicating duplicate
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'is_duplicate' => true,
                            'duplicate_id' => $existingItem->id,
                            'reasoning' => 'This is the same Unauthenticated error in UAT xwave-app'
                        ])
                    ]
                ]
            ]
        ], 200)
    ]);

    $newAction = 'Fix Unauthenticated error in xwave-app UAT: minified:aua throwing Unauthorised: message: Unauthenticated';

    $result = $this->deduplicationService->isDuplicate($newAction, 'high', 'Sentry');

    expect($result['is_duplicate'])->toBeTrue();
    expect($result['duplicate_of'])->not->toBeNull();
    expect($result['duplicate_of']->id)->toBe($existingItem->id);
});

it('does not detect duplicate when error is in different environment', function () {
    // Create an existing UAT error
    ActionItem::create([
        'message_id' => 1,
        'source' => 'sentry',
        'action' => 'Fix Unauthenticated error in xwave-app UAT: minified:aua throwing Unauthorised',
        'priority' => 'high',
        'sender' => 'Sentry',
        'synced' => false,
    ]);

    // Mock Groq API response indicating NOT duplicate
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'is_duplicate' => false,
                            'duplicate_id' => null,
                            'reasoning' => 'Different environment (Production vs UAT)'
                        ])
                    ]
                ]
            ]
        ], 200)
    ]);

    $newAction = 'Fix Unauthenticated error in xwave-app PRODUCTION: minified:aua throwing Unauthorised';

    $result = $this->deduplicationService->isDuplicate($newAction, 'high', 'Sentry');

    expect($result['is_duplicate'])->toBeFalse();
    expect($result['duplicate_of'])->toBeNull();
});

it('returns false when no recent tasks exist', function () {
    $result = $this->deduplicationService->isDuplicate(
        'Fix database timeout in checkout',
        'high',
        'Monitoring'
    );

    expect($result['is_duplicate'])->toBeFalse();
    expect($result['duplicate_of'])->toBeNull();
    expect($result['reasoning'])->toBe('No recent tasks to compare against');
});

it('only checks tasks from last 48 hours', function () {
    // Create an old task (3 days ago)
    $oldTask = ActionItem::create([
        'message_id' => 1,
        'source' => 'sentry',
        'action' => 'Fix database connection timeout',
        'priority' => 'high',
        'sender' => 'Sentry',
        'synced' => false,
        'created_at' => now()->subDays(3),
    ]);

    // Mock Groq API to return NOT duplicate (because old task shouldn't be in the list)
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'is_duplicate' => false,
                            'duplicate_id' => null,
                            'reasoning' => 'No recent tasks to compare against'
                        ])
                    ]
                ]
            ]
        ], 200)
    ]);

    $result = $this->deduplicationService->isDuplicate(
        'Fix database connection timeout',
        'high',
        'Sentry'
    );

    // Should not find the old task as duplicate
    expect($result['is_duplicate'])->toBeFalse();
});

it('handles Groq API errors gracefully', function () {
    ActionItem::create([
        'message_id' => 1,
        'source' => 'sentry',
        'action' => 'Fix some error',
        'priority' => 'high',
        'sender' => 'Sentry',
        'synced' => false,
    ]);

    // Mock API error
    Http::fake([
        'api.groq.com/*' => Http::response([], 500)
    ]);

    $result = $this->deduplicationService->isDuplicate(
        'Fix another error',
        'high',
        'Sentry'
    );

    expect($result['is_duplicate'])->toBeFalse();
    expect($result['reasoning'])->toContain('API error occurred');
});
