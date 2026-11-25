<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('canonical_audiences',function(Blueprint $t){$t->id();$t->foreignId('connected_ad_account_id')->constrained()->cascadeOnDelete();$t->string('provider',32);$t->string('external_audience_id',255);$t->timestampTz('first_observed_at');$t->unique(['connected_ad_account_id','provider','external_audience_id'],'canonical_audience_provider_identity');});
  Schema::create('audience_targeting_observations',function(Blueprint $t){$t->id();$t->foreignId('ad_set_id')->constrained()->cascadeOnDelete();$t->foreignId('sync_run_id')->constrained()->cascadeOnDelete();$t->foreignId('raw_provider_payload_id')->constrained()->cascadeOnDelete();$t->boolean('included_observed');$t->boolean('excluded_observed');$t->string('references_sha256',64);$t->timestampTz('observed_at');$t->unique(['ad_set_id','sync_run_id'],'audience_targeting_one_set_per_run');});
  Schema::create('audience_targeting_references',function(Blueprint $t){$t->id();$t->foreignId('audience_targeting_observation_id')->constrained()->cascadeOnDelete();$t->foreignId('canonical_audience_id')->constrained()->cascadeOnDelete();$t->string('role',16);$t->string('name',255)->nullable();$t->unique(['audience_targeting_observation_id','canonical_audience_id','role'],'audience_targeting_role_identity');});
 }
 public function down():void{Schema::dropIfExists('audience_targeting_references');Schema::dropIfExists('audience_targeting_observations');Schema::dropIfExists('canonical_audiences');}
};
