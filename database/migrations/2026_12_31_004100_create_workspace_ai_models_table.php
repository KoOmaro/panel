<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 20);
            $table->string('provider', 20);
            $table->string('model', 120);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'feature']);
        });

        Schema::create('workspace_ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('provider', 20);
            $table->string('model', 120);
            $table->string('feature', 20);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'day', 'provider', 'model', 'feature'], 'workspace_ai_usage_unique');
            $table->index(['workspace_id', 'provider', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_ai_usage');
        Schema::dropIfExists('workspace_ai_models');
    }
};
