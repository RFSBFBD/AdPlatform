<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('canonical_creatives',function(Blueprint $t){$t->id();$t->foreignId('connected_ad_account_id')->constrained()->cascadeOnDelete();$t->string('provider',32);$t->string('external_creative_id',255);$t->timestampTz('first_observed_at');$t->unique(['connected_ad_account_id','provider','external_creative_id'],'canonical_creative_provider_identity');});
  Schema::create('creative_observations',function(Blueprint $t){$t->id();$t->foreignId('canonical_creative_id')->constrained()->cascadeOnDelete();$t->foreignId('ad_id')->constrained()->cascadeOnDelete();$t->foreignId('sync_run_id')->constrained()->cascadeOnDelete();$t->foreignId('raw_provider_payload_id')->constrained()->cascadeOnDelete();$t->string('name',255)->nullable();$t->string('object_type',64)->nullable();$t->string('descriptor_sha256',64);$t->timestampTz('observed_at');$t->unique(['ad_id','sync_run_id'],'creative_one_observation_per_ad_run');$t->index(['canonical_creative_id','id']);});
 }
 public function down():void{Schema::dropIfExists('creative_observations');Schema::dropIfExists('canonical_creatives');}
};
