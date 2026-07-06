<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('ticket_id')->references('id')->on('tickets')->nullOnDelete();
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('ticket_histories', function (Blueprint $table) {
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropForeign(['user_id', 'assigned_to']));
        Schema::table('notifications', fn (Blueprint $table) => $table->dropForeign(['user_id', 'ticket_id']));
        Schema::table('comments', fn (Blueprint $table) => $table->dropForeign(['ticket_id', 'user_id']));
        Schema::table('ticket_histories', fn (Blueprint $table) => $table->dropForeign(['ticket_id', 'user_id']));
    }
};