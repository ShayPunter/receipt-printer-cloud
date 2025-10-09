<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionItem extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'message_id',
        'source',
        'action',
        'priority',
        'sender',
        'synced',
        'synced_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'synced' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /**
     * Get the message that this action item belongs to.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Scope a query to only include unsynced action items.
     */
    public function scopeUnsynced($query)
    {
        return $query->where('synced', false);
    }

    /**
     * Scope a query to only include synced action items.
     */
    public function scopeSynced($query)
    {
        return $query->where('synced', true);
    }

    /**
     * Scope a query to filter by source.
     */
    public function scopeFromSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Scope a query to filter by priority.
     */
    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope a query to filter by high priority.
     */
    public function scopeHighPriority($query)
    {
        return $query->where('priority', 'high');
    }

    /**
     * Mark this action item as synced.
     */
    public function markAsSynced(): bool
    {
        return $this->update([
            'synced' => true,
            'synced_at' => now(),
        ]);
    }
}
