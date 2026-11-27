<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_registries', function (Blueprint $table) {
            $table->foreignId('integration_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::create('docker_hub_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('service', 63);
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('repository', 255);
            $table->string('tag', 128);
            $table->string('digest', 71)->nullable();
            $table->boolean('redeploy_on_push')->default(false);
            $table->foreignId('push_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hook_key', 64)->nullable()->unique();
            $table->text('hook_token')->nullable();
            $table->timestamp('first_delivery_at')->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamp('last_push_at')->nullable();
            $table->string('last_push_digest', 71)->nullable();
            $table->timestamps();

            $table->unique(['machine_id', 'service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docker_hub_apps');
        Schema::table('container_registries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('integration_id');
        });
    }
};
