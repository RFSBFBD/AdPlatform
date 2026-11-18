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
        Schema::create('provider_developer_configs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->unique();
            $table->string('app_id', 255)->nullable();
            $table->text('app_secret')->nullable(); // Encrypted at rest
            $table->text('developer_token')->nullable(); // Encrypted at rest
            $table->string('redirect_uri', 500)->nullable();
            $table->string('api_version', 50)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->jsonb('extra_settings')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('test_status', 50)->default('untested');
            $table->text('test_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_developer_configs');
    }
};
