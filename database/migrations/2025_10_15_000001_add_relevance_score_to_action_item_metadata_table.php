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
        Schema::table('action_item_metadata', function (Blueprint $table) {
            $table->decimal('relevance_score', 3, 2)->nullable()->after('confidence');
            $table->index('relevance_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('action_item_metadata', function (Blueprint $table) {
            $table->dropIndex(['relevance_score']);
            $table->dropColumn('relevance_score');
        });
    }
};
