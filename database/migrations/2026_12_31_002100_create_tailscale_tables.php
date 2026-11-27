<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tailscale_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 64);
            $table->string('name', 255);
            $table->string('hostname', 255);
            $table->json('addresses')->nullable();
            $table->json('tags')->nullable();
            $table->string('os', 40)->nullable();
            $table->string('client_version', 80)->nullable();
            $table->boolean('authorized')->default(true);
            $table->boolean('external')->default(false);
            $table->boolean('connected')->nullable();
            $table->json('endpoints')->nullable();
            $table->string('linked_by', 20)->nullable();
            $table->timestamp('created_at_tailnet')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'device_id']);
            $table->index('machine_id');
        });

        Schema::create('tailscale_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('state', 20)->default('running');
            $table->string('step', 20);
            $table->boolean('manual')->default(false);
            $table->string('hostname', 63);
            $table->json('tags')->nullable();
            $table->string('key_id', 64)->nullable();
            $table->timestamp('key_expires_at')->nullable();
            $table->uuid('job_id')->nullable();
            $table->string('device_id', 64)->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('checks')->default(0);
            $table->timestamp('step_started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['machine_id', 'state']);
            $table->index(['integration_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tailscale_changes');
        Schema::dropIfExists('tailscale_devices');
    }
};
