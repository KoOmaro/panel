<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Proxmox task ids (UPIDs) carry the node, task and token: longer than 64 characters.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_operations', function (Blueprint $table) {
            $table->string('action_id', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('integration_operations', function (Blueprint $table) {
            $table->string('action_id', 64)->nullable()->change();
        });
    }
};
