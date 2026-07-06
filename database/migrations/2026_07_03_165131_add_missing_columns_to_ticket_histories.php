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
        Schema::table('ticket_histories', function (Blueprint $table) {
            $table->text('action')->nullable();
            $table->text('new_value')->nullable();
            $table->text('old_value')->nullable();
            $table->text('changed_by')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticket_histories', function (Blueprint $table) {
            $table->dropColumn(['action', 'new_value', 'old_value', 'changed_by']);
        });
    }
};
