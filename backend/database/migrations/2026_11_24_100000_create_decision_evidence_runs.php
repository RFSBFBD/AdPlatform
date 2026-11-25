<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive only. Existing rounded metrics and archives remain untouched.
        Schema::create('decision_evidence_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pipeline_version');
            $table->string('snapshot_id', 64);
            $table->string('problem_id', 64);
            $table->string('audit_hash', 64);
            $table->longText('evidence_json'); // Exact numeric operands are JSON strings, not fixed-scale decimals.
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_evidence_runs');
    }
};
