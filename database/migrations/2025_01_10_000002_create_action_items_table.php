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
        Schema::create('action_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->onDelete('cascade');
            $table->string('source'); // Denormalized from message for easier querying
            $table->text('action'); // The extracted actionable item
            $table->boolean('synced')->default(false); // Sync status for external apps
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('source');
            $table->index('synced');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('action_items');
    }
};
