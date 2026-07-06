<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('name');
            $table->text('email')->unique();
            $table->text('password');
            $table->text('role')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('title');
            $table->text('description');
            $table->text('status')->nullable();
            $table->text('priority')->nullable();
            $table->text('category');
            $table->uuid('user_id')->nullable();
            $table->uuid('assigned_to')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->text('role')->nullable();
            $table->text('title');
            $table->text('message');
            $table->uuid('ticket_id')->nullable();
            $table->boolean('is_read')->nullable();
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->text('content');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('ticket_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->text('old_status')->nullable();
            $table->text('new_status');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->string('avatar')->nullable();   // <-- tambahin ini
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_histories');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('users');
    }
};