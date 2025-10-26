<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recurring_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->enum('frequency_type', ['daily', 'weekly', 'monthly', 'custom'])->default('daily');
            $table->integer('frequency_value')->default(1); // For custom: e.g., every 3 days
            $table->enum('frequency_unit', ['minutes', 'hours', 'days', 'weeks', 'months'])->default('days');
            $table->string('day_of_week')->nullable(); // For weekly: monday, tuesday, etc.
            $table->integer('day_of_month')->nullable(); // For monthly: 1-31
            $table->time('time_of_day')->nullable(); // Specific time to create task
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('user_id');
            $table->index('is_active');
            $table->index('next_run_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_tasks');
    }
};
