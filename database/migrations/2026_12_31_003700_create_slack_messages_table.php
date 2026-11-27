<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a Slack alert or deploy notice said, so ServerOS can redraw it when it's fixed or someone acts on it from its buttons.
        Schema::create('slack_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_post_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 40);
            $table->string('ts', 40);
            $table->string('kind', 20);
            $table->json('payload');
            $table->json('notes')->nullable();
            $table->uuid('job_id')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'channel', 'ts']);
            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slack_messages');
    }
};
