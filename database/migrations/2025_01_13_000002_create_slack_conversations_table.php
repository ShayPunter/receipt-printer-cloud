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
        Schema::create('slack_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_key')->unique(); // channel:thread_ts or channel:general:timewindow
            $table->string('channel'); // Slack channel ID
            $table->string('thread_ts')->nullable(); // Thread timestamp (null for non-threaded)
            $table->json('messages'); // Array of messages with sender, body, timestamp
            $table->timestamp('first_message_at'); // When first message arrived
            $table->timestamp('last_message_at'); // When last message arrived
            $table->boolean('processed')->default(false); // Whether conversation has been processed
            $table->timestamp('processed_at')->nullable(); // When conversation was processed
            $table->unsignedBigInteger('message_id')->nullable(); // Link to messages table after processing

            $table->timestamps();

            // Indexes for performance
            $table->index('processed');
            $table->index('first_message_at');
            $table->index('last_message_at');
            $table->index(['channel', 'thread_ts']);

            // Foreign key to messages table
            $table->foreign('message_id')
                  ->references('id')
                  ->on('messages')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_conversations');
    }
};
