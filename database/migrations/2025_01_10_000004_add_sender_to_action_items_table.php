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
            $table->string('sender')->nullable()->after('priority');

            // Add index for sender filtering
            $table->index('sender');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('action_items', function (Blueprint $table) {
            $table->dropIndex(['sender']);
            $table->dropColumn('sender');
        });
    }
};
