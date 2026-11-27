<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_ssh_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->boolean('external')->default(false);
            $table->string('login', 32);
            $table->text('public_key')->nullable();
            $table->string('key_type', 40)->nullable();
            $table->string('fingerprint', 80)->nullable();
            $table->string('key_comment', 120)->nullable();
            $table->string('state', 20);
            $table->string('note', 300)->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('added_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('removed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->uuid('job_id')->nullable()->index();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['machine_id', 'state']);
            $table->index(['state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_ssh_access');
    }
};
