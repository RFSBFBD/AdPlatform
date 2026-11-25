<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration{
 public function up():void{
  Schema::create('conversion_definitions',function(Blueprint $t){$t->id();$t->foreignId('connected_ad_account_id')->constrained()->cascadeOnDelete();$t->string('external_key',128);$t->string('name',255);$t->string('kind',32);$t->string('counting_method',32);$t->string('attribution_model',128);$t->unsignedInteger('click_window_days');$t->unsignedInteger('view_window_days');$t->string('source_system',128);$t->timestamps();$t->unique(['connected_ad_account_id','external_key']);});
  Schema::create('typed_conversion_daily',function(Blueprint $t){$t->id();$t->foreignId('conversion_definition_id')->constrained()->cascadeOnDelete();$t->string('entity_level',16);$t->unsignedBigInteger('entity_id');$t->date('date');$t->decimal('count',18,4);$t->string('currency',3);$t->string('timezone',100);$t->string('source_reference',255);$t->timestampTz('retrieved_at');$t->timestamps();$t->unique(['conversion_definition_id','entity_level','entity_id','date'],'typed_conversion_daily_scope_unique');});
 }
 public function down():void{Schema::dropIfExists('typed_conversion_daily');Schema::dropIfExists('conversion_definitions');}
};
