<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration{
 public function up():void{Schema::table('optimization_runs',fn(Blueprint $t)=>$t->string('archive_integrity_sha256',64)->nullable());}
 public function down():void{Schema::table('optimization_runs',fn(Blueprint $t)=>$t->dropColumn('archive_integrity_sha256'));}
};
