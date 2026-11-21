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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('exchange_rate', 18, 8);
            $table->date('effective_date');
            $table->string('source')->default('manual');
            $table->timestamp('retrieved_at')->useCurrent();
            $table->timestamps();

            $table->unique(['base_currency', 'quote_currency', 'effective_date'], 'exchange_rate_unique');
            $table->index(['effective_date', 'base_currency', 'quote_currency'], 'exchange_rate_lookup');
        });

        if (!Schema::hasColumn('users', 'reporting_currency')) {
            Schema::table('users', function (Blueprint $table) {
                $table->char('reporting_currency', 3)->default('USD')->after('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'reporting_currency')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('reporting_currency');
            });
        }

        Schema::dropIfExists('exchange_rates');
    }
};
