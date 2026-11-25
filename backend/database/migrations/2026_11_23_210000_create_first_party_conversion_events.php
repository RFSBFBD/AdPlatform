<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('first_party_conversion_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversion_definition_id')->constrained()->cascadeOnDelete();
            $t->string('event_key', 128);
            $t->string('entity_level', 16);
            $t->unsignedBigInteger('entity_id');
            $t->timestampTz('occurred_at');
            $t->decimal('value', 18, 4);
            $t->string('currency', 3);
            $t->string('payload_sha256', 64);
            $t->timestampTz('accepted_at');
            $t->unique(['conversion_definition_id', 'event_key'], 'first_party_event_scope_unique');
            $t->index(['conversion_definition_id', 'id']);
        });
    }
    public function down(): void { Schema::dropIfExists('first_party_conversion_events'); }
};
