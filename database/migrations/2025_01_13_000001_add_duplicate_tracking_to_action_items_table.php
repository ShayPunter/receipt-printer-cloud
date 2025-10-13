<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if columns already exist and drop them first
        if (Schema::hasColumn('action_items', 'is_duplicate')) {
            Schema::table('action_items', function (Blueprint $table) {
                // Check if foreign key exists before dropping
                $foreignKeys = DB::select(
                    "SELECT CONSTRAINT_NAME
                     FROM information_schema.TABLE_CONSTRAINTS
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'action_items'
                     AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                     AND CONSTRAINT_NAME = 'action_items_duplicate_of_id_foreign'"
                );

                if (count($foreignKeys) > 0) {
                    $table->dropForeign(['duplicate_of_id']);
                }

                $table->dropColumn(['is_duplicate', 'duplicate_of_id', 'duplicate_reasoning']);
            });
        }

        // Now add the columns fresh
        Schema::table('action_items', function (Blueprint $table) {
            $table->boolean('is_duplicate')->default(false)->after('synced');
            $table->unsignedBigInteger('duplicate_of_id')->nullable()->after('is_duplicate');
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
