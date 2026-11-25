<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recorded_reporting_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_ad_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_run_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->unsignedInteger('page_index');
            $table->longText('body');
            $table->string('body_sha256', 64);
            $table->json('provenance');
            $table->longText('native_metrics_json')->nullable();
            $table->string('admission_state');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['sync_run_id', 'page_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recorded_reporting_responses');
    }
};
