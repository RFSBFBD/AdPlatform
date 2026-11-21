<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Remove any historical duplicates before applying unique constraint
        DB::statement("
            DELETE FROM performance_metrics a USING performance_metrics b
            WHERE a.id < b.id
              AND a.connected_ad_account_id = b.connected_ad_account_id
              AND a.date = b.date
              AND a.provider = b.provider
              AND (a.campaign_id IS NOT DISTINCT FROM b.campaign_id)
              AND (a.ad_set_id IS NOT DISTINCT FROM b.ad_set_id)
              AND (a.ad_id IS NOT DISTINCT FROM b.ad_id);
        ");

        // 2. Add NULLS NOT DISTINCT unique index across hierarchical entity identifiers
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS performance_metrics_hierarchy_unique 
            ON performance_metrics (connected_ad_account_id, campaign_id, ad_set_id, ad_id, date, provider) NULLS NOT DISTINCT;
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS performance_metrics_hierarchy_unique;");
    }
};
