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
        // 1. Raw Provider Payloads (Traceability & Audit)
        Schema::create('raw_provider_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_ad_account_id')->constrained('connected_ad_accounts')->cascadeOnDelete();
            $table->string('provider');
            $table->string('endpoint');
            $table->date('reporting_date')->nullable();
            $table->jsonb('payload');
            $table->timestamps();

            $table->index(['connected_ad_account_id', 'reporting_date']);
            $table->index(['provider', 'endpoint']);
        });

        // 2. Campaigns
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_ad_account_id')->constrained('connected_ad_accounts')->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_campaign_id');
            $table->string('name');
            $table->string('status')->default('ACTIVE');
            $table->string('objective')->nullable();
            $table->decimal('daily_budget', 14, 2)->nullable();
            $table->decimal('lifetime_budget', 14, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['connected_ad_account_id', 'external_campaign_id']);
            $table->index(['provider', 'status']);
        });

        // 3. Ad Sets / Ad Groups
        Schema::create('ad_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('external_ad_set_id');
            $table->string('name');
            $table->string('status')->default('ACTIVE');
            $table->jsonb('targeting')->nullable();
            $table->decimal('daily_budget', 14, 2)->nullable();
            $table->decimal('lifetime_budget', 14, 2)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'external_ad_set_id']);
        });

        // 4. Ads
        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_set_id')->constrained('ad_sets')->cascadeOnDelete();
            $table->string('external_ad_id');
            $table->string('name');
            $table->string('status')->default('ACTIVE');
            $table->string('creative_type')->nullable();
            $table->text('creative_url')->nullable();
            $table->string('headline')->nullable();
            $table->text('body_text')->nullable();
            $table->text('destination_url')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['ad_set_id', 'external_ad_id']);
        });

        // 5. Performance Metrics (Daily normalized time-series)
        Schema::create('performance_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_ad_account_id')->constrained('connected_ad_accounts')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('ad_set_id')->nullable()->constrained('ad_sets')->cascadeOnDelete();
            $table->foreignId('ad_id')->nullable()->constrained('ads')->cascadeOnDelete();
            $table->string('provider');
            $table->date('date');
            $table->decimal('spend', 14, 4)->default(0);
            $table->bigInteger('impressions')->default(0);
            $table->bigInteger('clicks')->default(0);
            $table->decimal('conversions', 14, 4)->default(0);
            $table->decimal('conversion_value', 14, 4)->default(0);
            $table->decimal('cpc', 10, 4)->default(0);
            $table->decimal('cpm', 10, 4)->default(0);
            $table->decimal('ctr', 8, 4)->default(0);
            $table->decimal('roas', 10, 4)->default(0);
            $table->decimal('cpa', 10, 4)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['connected_ad_account_id', 'date']);
            $table->index(['campaign_id', 'date']);
            $table->index(['provider', 'date']);
        });

        // 6. Sync Runs
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_ad_account_id')->constrained('connected_ad_accounts')->cascadeOnDelete();
            $table->string('sync_type')->default('incremental'); // initial, incremental, historical, manual
            $table->string('status')->default('pending');        // pending, running, completed, failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('records_processed')->default(0);
            $table->text('error_message')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['connected_ad_account_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('performance_metrics');
        Schema::dropIfExists('ads');
        Schema::dropIfExists('ad_sets');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('raw_provider_payloads');
    }
};
