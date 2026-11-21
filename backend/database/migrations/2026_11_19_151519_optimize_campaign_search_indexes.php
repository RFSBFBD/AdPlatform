<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Applies PostgreSQL best practices for fast campaign search and tenancy filtering.
     */
    public function up(): void
    {
        // 1. Enable pg_trgm extension for GIN trigram indexing on PostgreSQL
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');

        // 2. Add GIN Trigram indexes on campaigns name & external_campaign_id
        DB::statement('CREATE INDEX IF NOT EXISTS idx_campaigns_name_trgm ON campaigns USING gin (name gin_trgm_ops);');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_campaigns_ext_id_trgm ON campaigns USING gin (external_campaign_id gin_trgm_ops);');

        // 3. Add compound index for filtering campaigns by account, provider, and status
        DB::statement('CREATE INDEX IF NOT EXISTS idx_campaigns_account_provider_status ON campaigns (connected_ad_account_id, provider, status);');

        // 4. Add index on connected_ad_accounts (user_id, id) for rapid subquery tenancy resolution
        DB::statement('CREATE INDEX IF NOT EXISTS idx_connected_ad_accounts_user_id_id ON connected_ad_accounts (user_id, id);');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_campaigns_name_trgm;');
        DB::statement('DROP INDEX IF EXISTS idx_campaigns_ext_id_trgm;');
        DB::statement('DROP INDEX IF EXISTS idx_campaigns_account_provider_status;');
        DB::statement('DROP INDEX IF EXISTS idx_connected_ad_accounts_user_id_id;');
    }
};
