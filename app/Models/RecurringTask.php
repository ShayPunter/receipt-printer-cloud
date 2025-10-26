<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringTask extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'priority',
        'frequency_type',
        'frequency_value',
        'frequency_unit',
        'day_of_week',
        'day_of_month',
        'time_of_day',
        'is_active',
        'next_run_at',
        'last_run_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'day_of_month' => 'integer',
        'frequency_value' => 'integer',
    ];

    /**
     * Get the user that owns the recurring task.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include active recurring tasks.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include tasks that are due to run.
     */
    public function scopeDueToRun($query)
    {
        return $query->where('is_active', true)
                     ->where(function ($q) {
                         $q->whereNull('next_run_at')
                           ->orWhere('next_run_at', '<=', now());
                     });
    }

    /**
     * Calculate and set the next run time based on frequency settings.
     */
    public function calculateNextRunTime(): void
    {
        $baseTime = $this->last_run_at ?? now();

        $nextRun = match ($this->frequency_type) {
            'daily' => $this->calculateDailyNextRun($baseTime),
            'weekly' => $this->calculateWeeklyNextRun($baseTime),
            'monthly' => $this->calculateMonthlyNextRun($baseTime),
            'custom' => $this->calculateCustomNextRun($baseTime),
            default => now()->addDay(),
        };

        // Apply time of day if set
        if ($this->time_of_day) {
            [$hours, $minutes] = explode(':', $this->time_of_day);
            $nextRun->setTime((int) $hours, (int) $minutes, 0);
        }

        $this->next_run_at = $nextRun;
    }

    /**
     * Calculate next run for daily frequency.
     */
    protected function calculateDailyNextRun(Carbon $baseTime): Carbon
    {
        return $baseTime->copy()->addDay();
    }

    /**
     * Calculate next run for weekly frequency.
     */
    protected function calculateWeeklyNextRun(Carbon $baseTime): Carbon
    {
        $nextRun = $baseTime->copy()->addWeek();

        if ($this->day_of_week) {
            // Map day names to Carbon constants
            $dayMap = [
                'monday' => Carbon::MONDAY,
                'tuesday' => Carbon::TUESDAY,
                'wednesday' => Carbon::WEDNESDAY,
                'thursday' => Carbon::THURSDAY,
                'friday' => Carbon::FRIDAY,
                'saturday' => Carbon::SATURDAY,
                'sunday' => Carbon::SUNDAY,
            ];

            $targetDay = $dayMap[strtolower($this->day_of_week)] ?? Carbon::MONDAY;
            $nextRun->next($targetDay);
        }

        return $nextRun;
    }

    /**
     * Calculate next run for monthly frequency.
     */
    protected function calculateMonthlyNextRun(Carbon $baseTime): Carbon
    {
        $nextRun = $baseTime->copy()->addMonth();

        if ($this->day_of_month) {
            $nextRun->day(min($this->day_of_month, $nextRun->daysInMonth));
        }

        return $nextRun;
    }

    /**
     * Calculate next run for custom frequency.
     */
    protected function calculateCustomNextRun(Carbon $baseTime): Carbon
    {
        return match ($this->frequency_unit) {
            'minutes' => $baseTime->copy()->addMinutes($this->frequency_value),
            'hours' => $baseTime->copy()->addHours($this->frequency_value),
            'days' => $baseTime->copy()->addDays($this->frequency_value),
            'weeks' => $baseTime->copy()->addWeeks($this->frequency_value),
            'months' => $baseTime->copy()->addMonths($this->frequency_value),
            default => $baseTime->copy()->addDay(),
        };
    }

    /**
     * Create an action item from this recurring task.
     */
    public function createActionItem(): ActionItem
    {
        // Create a message record first (required by ActionItem)
        $message = Message::create([
            'source' => 'recurring_task',
            'body' => "Recurring Task: {$this->title}\n\n{$this->description}",
            'processed' => true,
        ]);

        // Create the action item
        $actionItem = ActionItem::create([
            'message_id' => $message->id,
            'source' => 'recurring_task',
            'action' => $this->title,
            'priority' => $this->priority,
            'sender' => 'System (Recurring Task)',
            'synced' => false,
        ]);

        // Update this recurring task
        $this->update([
            'last_run_at' => now(),
        ]);

        $this->calculateNextRunTime();
        $this->save();

        return $actionItem;
    }

    /**
     * Get a human-readable description of the frequency.
     */
    public function getFrequencyDescriptionAttribute(): string
    {
        return match ($this->frequency_type) {
            'daily' => 'Every day',
            'weekly' => $this->day_of_week
                ? 'Every ' . ucfirst($this->day_of_week)
                : 'Every week',
            'monthly' => $this->day_of_month
                ? 'Every month on day ' . $this->day_of_month
                : 'Every month',
            'custom' => "Every {$this->frequency_value} {$this->frequency_unit}",
            default => 'Unknown',
        } . ($this->time_of_day ? ' at ' . Carbon::parse($this->time_of_day)->format('g:i A') : '');
    }
}
