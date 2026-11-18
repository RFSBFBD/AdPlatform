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
        Schema::create('connected_ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('provider', 50)->index();
            $table->string('external_account_id', 255)->index();
            $table->string('account_name', 255);
            $table->string('currency', 10)->default('USD');
            $table->string('timezone', 100)->default('UTC');
            $table->text('access_token'); // Encrypted at rest
            $table->text('refresh_token')->nullable(); // Encrypted at rest
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 50)->default('connected');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connected_ad_accounts');
    }
};
