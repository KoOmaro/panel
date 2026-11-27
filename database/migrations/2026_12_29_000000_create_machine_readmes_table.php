<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_readmes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('state', 16)->default('writing');
            $table->string('facts_hash', 64)->nullable();
            $table->json('sections')->nullable();
            $table->string('source', 16)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('reason', 16)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->json('seen_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_readmes');
    }
};
