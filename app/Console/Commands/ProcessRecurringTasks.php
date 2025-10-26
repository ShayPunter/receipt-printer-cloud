<?php

namespace App\Console\Commands;

use App\Models\RecurringTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessRecurringTasks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'recurring-tasks:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process recurring tasks and create action items for tasks that are due';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Processing recurring tasks...');

        // Get all recurring tasks that are due to run
        $dueTasks = RecurringTask::dueToRun()->get();

        if ($dueTasks->isEmpty()) {
            $this->info('No recurring tasks are due for processing.');
            return Command::SUCCESS;
        }

        $this->info("Found {$dueTasks->count()} task(s) to process.");

        $totalActionsCreated = 0;
        $totalFailed = 0;

        foreach ($dueTasks as $task) {
            try {
                $this->line("Processing: {$task->title} (ID: {$task->id})");

                // Create an action item from this recurring task
                $actionItem = $task->createActionItem();

                $totalActionsCreated++;
                $this->line("  [CREATED] Action item created (Priority: {$actionItem->priority})");
                $this->line("  Next run: {$task->next_run_at->format('Y-m-d H:i:s')}");

                Log::info('✓ RECURRING TASK PROCESSED', [
                    'recurring_task_id' => $task->id,
                    'task_title' => $task->title,
                    'action_item_id' => $actionItem->id,
                    'priority' => $actionItem->priority,
                    'next_run_at' => $task->next_run_at,
                    'frequency' => $task->frequency_description,
                ]);

            } catch (\Exception $e) {
                $totalFailed++;
                $this->error("Failed to process task '{$task->title}': {$e->getMessage()}");

                Log::error('Failed to process recurring task', [
                    'recurring_task_id' => $task->id,
                    'task_title' => $task->title,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $this->info("Processing complete!");
        $this->info("Total actions created: {$totalActionsCreated}");

        if ($totalFailed > 0) {
            $this->warn("Total failed: {$totalFailed}");
        }

        return Command::SUCCESS;
    }
}
