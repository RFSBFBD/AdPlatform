<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reproducibility record for every optimization run (Master Spec sections 44-45):
 * same input + same model/configuration => same output, and any recommendation
 * can be reproduced from this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optimization_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('engine');           // e.g. adplatform-or
            $table->string('engine_version');   // config('or.engine_version')
            $table->string('model_name')->nullable();      // assumed_exponential | fitted:<family>
            $table->string('model_version')->nullable();
            $table->string('status');           // ok | insufficient_data | ...
            $table->string('input_hash', 64);   // sha256 over canonical inputs + dataset fingerprint
            $table->json('inputs');             // budget, horizons, limits, options
            $table->json('dataset_fingerprint');// window + per-channel aggregates hash
            $table->json('result');             // full OptimizationResultDto::toArray()
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('input_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optimization_runs');
    }
};
