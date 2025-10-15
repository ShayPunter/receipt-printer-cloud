<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlackConversation extends Model
{
    protected $fillable = [
        'conversation_key',
        'channel',
        'thread_ts',
        'messages',
        'first_message_at',
        'last_message_at',
        'processed',
        'processed_at',
        'message_id',
    ];

    protected $casts = [
        'messages' => 'array',
        'first_message_at' => 'datetime',
        'last_message_at' => 'datetime',
        'processed' => 'boolean',
        'processed_at' => 'datetime',
    ];

    /**
     * Get the message created from this conversation.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Scope for unprocessed conversations.
     */
    public function scopeUnprocessed($query)
    {
        return $query->where('processed', false);
    }

    /**
     * Scope for processed conversations.
     */
    public function scopeProcessed($query)
    {
        return $query->where('processed', true);
    }
}
