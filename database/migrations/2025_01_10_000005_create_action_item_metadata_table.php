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
        Schema::create('action_item_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_item_id')->constrained()->onDelete('cascade');
            $table->text('reasoning')->nullable(); // AI's reasoning for extraction
            $table->decimal('confidence', 3, 2)->nullable(); // Confidence level 0.00 to 1.00
            $table->timestamps();

            // Indexes
            $table->index('action_item_id');
            $table->index('confidence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('action_item_metadata');
    }
};
