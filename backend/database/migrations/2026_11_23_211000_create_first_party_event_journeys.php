<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void { Schema::create('first_party_event_journeys',function(Blueprint $t){$t->id();$t->foreignId('first_party_conversion_event_id')->unique()->constrained()->cascadeOnDelete();$t->json('touches');$t->string('payload_sha256',64);$t->timestampTz('accepted_at');}); }
 public function down():void { Schema::dropIfExists('first_party_event_journeys'); }
};
