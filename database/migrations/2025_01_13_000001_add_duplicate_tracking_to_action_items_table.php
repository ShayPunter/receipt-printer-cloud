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
        Schema::table('action_items', function (Blueprint $table) {
            $table->boolean('is_duplicate')->default(false)->after('synced');
            $table->uuid('duplicate_of_id')->nullable()->after('is_duplicate');
            $table->text('duplicate_reasoning')->nullable()->after('duplicate_of_id');

            // Add foreign key constraint
            $table->foreign('duplicate_of_id')
                  ->references('id')
                  ->on('action_items')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('action_items', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of_id']);
            $table->dropColumn(['is_duplicate', 'duplicate_of_id', 'duplicate_reasoning']);
        });
    }
};
