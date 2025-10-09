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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // e.g., 'gmail', 'slack', 'webhook'
            $table->text('body'); // The raw message content
            $table->boolean('processed')->default(false); // Whether AI has extracted action items
            $table->timestamps();

            // Indexes for performance
            $table->index('source');
            $table->index('processed');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
