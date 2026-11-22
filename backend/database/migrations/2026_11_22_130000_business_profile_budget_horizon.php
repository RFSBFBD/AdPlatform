<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The cap used to be called monthly_budget_cap with no stated period. A bare "monthly" is not
    // equal to the 30-day data window, so the horizon is now an explicit, required companion.
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->renameColumn('monthly_budget_cap', 'budget_cap');
            $table->string('budget_cap_horizon', 20)->nullable(); // daily | per_30_days
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn('budget_cap_horizon');
            $table->renameColumn('budget_cap', 'monthly_budget_cap');
        });
    }
};
