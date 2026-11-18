<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. User AI Configurations (BYOK)
        Schema::create('ai_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider'); // openai, anthropic, gemini
            $table->text('api_key');    // Encrypted at rest
            $table->string('model')->default('gpt-4o');
            $table->boolean('is_active')->default(true);
            $table->integer('monthly_token_limit')->nullable();
            $table->integer('current_month_tokens')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });

        // 2. AI Usage & Audit Logs
        Schema::create('ai_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider');
            $table->string('action'); // generate_copy, analyze_performance, creative_recommendations
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('estimated_cost', 8, 4)->default(0.0000);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_audit_logs');
        Schema::dropIfExists('ai_configs');
    }
};
