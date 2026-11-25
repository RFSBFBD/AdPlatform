<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('performance_metrics', function (Blueprint $table) {
            $table->unsignedBigInteger('reach')->nullable();
            $table->decimal('frequency', 16, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('performance_metrics', fn(Blueprint $table) => $table->dropColumn(['reach', 'frequency']));
    }
};
