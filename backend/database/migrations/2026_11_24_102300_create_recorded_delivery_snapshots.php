<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recorded_delivery_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_ad_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('snapshot_id', 64);
            $table->longText('body');
            $table->string('body_sha256', 64);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recorded_delivery_snapshots');
    }
};
