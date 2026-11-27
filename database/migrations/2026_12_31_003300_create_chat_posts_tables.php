<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each alert ServerOS posted to the workspace's Telegram chat or Discord channel, so its buttons know what they act on and the recovery can edit it.
        Schema::create('chat_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('chat', 20);
            $table->string('destination', 100);
            $table->string('message_id', 100)->nullable();
            $table->string('thread', 200)->nullable();
            $table->string('event', 60);
            $table->string('subject', 200);
            $table->string('state', 20);
            $table->json('context')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'chat', 'thread', 'state']);
            $table->index('created_at');
        });

        // A Telegram or Discord account a workspace member linked to themselves with a one-time code.
        Schema::create('chat_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('chat', 20);
            $table->string('external_id', 64);
            $table->string('external_name', 120)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'chat', 'external_id']);
            $table->unique(['workspace_id', 'chat', 'user_id']);
        });

        // The workspace's Telegram bot as ServerOS set it up: the webhook's own address and secret (encrypted), and whether Telegram took it.
        Schema::create('telegram_bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('hook')->unique();
            $table->text('secret');
            $table->string('token_hash', 64);
            $table->string('username', 64)->nullable();
            $table->string('state', 20);
            $table->string('error', 300)->nullable();
            $table->unsignedBigInteger('last_update_id')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bots');
        Schema::dropIfExists('chat_identities');
        Schema::dropIfExists('chat_posts');
    }
};
