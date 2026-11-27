<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_samples', function (Blueprint $table) {
            $table->float('load_1')->nullable();
            $table->float('load_5')->nullable();
            $table->float('load_15')->nullable();
            $table->unsignedInteger('processes')->nullable();
            $table->unsignedBigInteger('net_rx')->nullable();
            $table->unsignedBigInteger('net_tx')->nullable();
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->float('load_1')->nullable();
            $table->float('load_5')->nullable();
            $table->float('load_15')->nullable();
            $table->unsignedInteger('processes')->nullable();
            $table->unsignedBigInteger('net_rx_rate')->nullable();
            $table->unsignedBigInteger('net_tx_rate')->nullable();
            $table->unsignedBigInteger('net_rx_bytes')->nullable();
            $table->unsignedBigInteger('net_tx_bytes')->nullable();
            $table->unsignedBigInteger('telemetry_ts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('machine_samples', function (Blueprint $table) {
            $table->dropColumn(['load_1', 'load_5', 'load_15', 'processes', 'net_rx', 'net_tx']);
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['load_1', 'load_5', 'load_15', 'processes', 'net_rx_rate', 'net_tx_rate', 'net_rx_bytes', 'net_tx_bytes', 'telemetry_ts']);
        });
    }
};
