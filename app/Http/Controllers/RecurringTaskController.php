<?php

namespace App\Http\Controllers;

use App\Models\RecurringTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecurringTaskController extends Controller
{
    /**
     * Display a listing of recurring tasks.
     */
    public function index(Request $request): Response
    {
        $recurringTasks = $request->user()
            ->recurringTasks()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'priority' => $task->priority,
                    'frequency_type' => $task->frequency_type,
                    'frequency_value' => $task->frequency_value,
                    'frequency_unit' => $task->frequency_unit,
                    'day_of_week' => $task->day_of_week,
                    'day_of_month' => $task->day_of_month,
                    'time_of_day' => $task->time_of_day,
                    'is_active' => $task->is_active,
                    'next_run_at' => $task->next_run_at?->toIso8601String(),
                    'last_run_at' => $task->last_run_at?->toIso8601String(),
                    'frequency_description' => $task->frequency_description,
                    'created_at' => $task->created_at->toIso8601String(),
                ];
            });

        return Inertia::render('RecurringTasks/Index', [
            'recurringTasks' => $recurringTasks,
        ]);
    }

    /**
     * Show the form for creating a new recurring task.
     */
    public function create(): Response
    {
        return Inertia::render('RecurringTasks/Create');
    }

    /**
     * Store a newly created recurring task.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['required', 'in:low,medium,high'],
            'frequency_type' => ['required', 'in:daily,weekly,monthly,custom'],
            'frequency_value' => ['nullable', 'integer', 'min:1'],
            'frequency_unit' => ['nullable', 'in:minutes,hours,days,weeks,months'],
            'day_of_week' => ['nullable', 'string', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'time_of_day' => ['nullable', 'date_format:H:i'],
            'is_active' => ['boolean'],
        ]);

        $recurringTask = $request->user()->recurringTasks()->create($validated);

        // Calculate the initial next run time
        $recurringTask->calculateNextRunTime();
        $recurringTask->save();

        return redirect()->route('recurring-tasks.index')
            ->with('success', 'Recurring task created successfully.');
    }

    /**
     * Show the form for editing a recurring task.
     */
    public function edit(Request $request, RecurringTask $recurringTask): Response
    {
        // Ensure user can only edit their own tasks
        if ($recurringTask->user_id !== $request->user()->id) {
            abort(403);
        }

        return Inertia::render('RecurringTasks/Edit', [
            'recurringTask' => [
                'id' => $recurringTask->id,
                'title' => $recurringTask->title,
                'description' => $recurringTask->description,
                'priority' => $recurringTask->priority,
                'frequency_type' => $recurringTask->frequency_type,
                'frequency_value' => $recurringTask->frequency_value,
                'frequency_unit' => $recurringTask->frequency_unit,
                'day_of_week' => $recurringTask->day_of_week,
                'day_of_month' => $recurringTask->day_of_month,
                'time_of_day' => $recurringTask->time_of_day,
                'is_active' => $recurringTask->is_active,
            ],
        ]);
    }

    /**
     * Update the specified recurring task.
     */
    public function update(Request $request, RecurringTask $recurringTask): RedirectResponse
    {
        // Ensure user can only update their own tasks
        if ($recurringTask->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['required', 'in:low,medium,high'],
            'frequency_type' => ['required', 'in:daily,weekly,monthly,custom'],
            'frequency_value' => ['nullable', 'integer', 'min:1'],
            'frequency_unit' => ['nullable', 'in:minutes,hours,days,weeks,months'],
            'day_of_week' => ['nullable', 'string', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'time_of_day' => ['nullable', 'date_format:H:i'],
            'is_active' => ['boolean'],
        ]);

        $recurringTask->update($validated);

        // Recalculate next run time if frequency changed
        $recurringTask->calculateNextRunTime();
        $recurringTask->save();

        return redirect()->route('recurring-tasks.index')
            ->with('success', 'Recurring task updated successfully.');
    }

    /**
     * Remove the specified recurring task.
     */
    public function destroy(Request $request, RecurringTask $recurringTask): RedirectResponse
    {
        // Ensure user can only delete their own tasks
        if ($recurringTask->user_id !== $request->user()->id) {
            abort(403);
        }

        $recurringTask->delete();

        return redirect()->route('recurring-tasks.index')
            ->with('success', 'Recurring task deleted successfully.');
    }

    /**
     * Toggle the active status of a recurring task.
     */
    public function toggle(Request $request, RecurringTask $recurringTask): RedirectResponse
    {
        // Ensure user can only toggle their own tasks
        if ($recurringTask->user_id !== $request->user()->id) {
            abort(403);
        }

        $recurringTask->update([
            'is_active' => !$recurringTask->is_active,
        ]);

        return back()->with('success', 'Recurring task ' . ($recurringTask->is_active ? 'activated' : 'deactivated') . ' successfully.');
    }
}
