<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bucket', 63);
            $table->string('bucket_id', 64)->nullable();
            $table->string('endpoint', 253);
            $table->string('region', 40)->nullable();
            $table->string('location', 40)->nullable();
            $table->string('jurisdiction', 16)->nullable();
            $table->string('key_id', 100)->nullable();
            $table->string('key_kind', 16)->nullable();
            $table->unsignedSmallInteger('keep_days')->nullable();
            $table->string('state', 16)->default('ready');
            $table->text('error')->nullable();
            $table->json('pending')->nullable();
            $table->json('usage')->nullable();
            $table->string('usage_error', 500)->nullable();
            $table->timestamp('usage_checked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['integration_id', 'bucket']);
            $table->index('backup_destination_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_buckets');
    }
};
