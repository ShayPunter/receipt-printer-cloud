<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionItemMetadata extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'action_item_id',
        'reasoning',
        'confidence',
        'relevance_score',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'confidence' => 'float',
        'relevance_score' => 'float',
    ];

    /**
     * Get the action item that this metadata belongs to.
     */
    public function actionItem(): BelongsTo
    {
        return $this->belongsTo(ActionItem::class);
    }

    /**
     * Scope a query to filter by minimum confidence level.
     */
    public function scopeMinConfidence($query, float $confidence)
    {
        return $query->where('confidence', '>=', $confidence);
    }

    /**
     * Scope a query to filter by high confidence (>= 0.8).
     */
    public function scopeHighConfidence($query)
    {
        return $query->where('confidence', '>=', 0.8);
    }

    /**
     * Scope a query to filter by low confidence (< 0.6).
     */
    public function scopeLowConfidence($query)
    {
        return $query->where('confidence', '<', 0.6);
    }

    /**
     * Scope a query to filter by minimum relevance level.
     */
    public function scopeMinRelevance($query, float $relevance)
    {
        return $query->where('relevance_score', '>=', $relevance);
    }

    /**
     * Scope a query to filter by high relevance (>= 0.7).
     */
    public function scopeHighRelevance($query)
    {
        return $query->where('relevance_score', '>=', 0.7);
    }

    /**
     * Scope a query to filter by low relevance (< 0.4).
     */
    public function scopeLowRelevance($query)
    {
        return $query->where('relevance_score', '<', 0.4);
    }
}
