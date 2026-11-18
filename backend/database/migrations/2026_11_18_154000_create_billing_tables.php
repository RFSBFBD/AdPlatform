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
        // 1. Account Billing Profiles
        Schema::create('account_billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_ad_account_id')->unique()->constrained('connected_ad_accounts')->cascadeOnDelete();
            $table->decimal('outstanding_balance', 14, 2)->default(0.00);
            $table->char('currency', 3)->default('USD');
            $table->string('payment_method_type')->nullable(); // credit_card, bank_transfer, paypal
            $table->string('payment_method_masked')->nullable(); // •••• 4242
            $table->string('status')->default('NORMAL');        // NORMAL, SUSPENDED, PENDING
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        // 2. Billing Invoices & Receipts
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_ad_account_id')->constrained('connected_ad_accounts')->cascadeOnDelete();
            $table->string('external_invoice_id');
            $table->date('invoice_date');
            $table->decimal('amount', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default(0.00);
            $table->decimal('total_amount', 14, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('status')->default('PAID'); // PAID, PENDING, FAILED, REFUNDED
            $table->text('download_url')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['connected_ad_account_id', 'external_invoice_id']);
            $table->index(['invoice_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('account_billing_profiles');
    }
};
