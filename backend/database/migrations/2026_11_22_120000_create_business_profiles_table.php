<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('business_name', 255)->nullable();
            $table->string('business_model', 50)->nullable(); // ecommerce | lead_gen | app | other
            $table->char('currency', 3)->nullable();
            $table->string('primary_goal', 50)->nullable(); // revenue | roas | leads | profit
            $table->decimal('gross_margin_percent', 5, 2)->nullable();
            $table->decimal('average_order_value', 14, 2)->nullable();
            $table->decimal('monthly_budget_cap', 14, 2)->nullable();
            $table->decimal('target_roas', 8, 2)->nullable();
            $table->string('conversion_definition', 255)->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('custom_fields')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
