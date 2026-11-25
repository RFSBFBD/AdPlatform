<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('provider_report_jobs',function(Blueprint $t) {
        $t->id();$t->foreignId('connected_ad_account_id')->constrained()->cascadeOnDelete();
        $t->string('provider');$t->string('external_report_id');$t->date('start_date');$t->date('end_date');
        $t->string('state')->default('pending');$t->unsignedInteger('poll_count')->default(0);
        $t->timestamp('deadline_at');$t->timestamp('next_poll_at')->nullable();$t->text('error')->nullable();
        $t->json('metrics')->nullable();$t->timestamps();$t->index(['connected_ad_account_id','state']);
    }); }
    public function down(): void { Schema::dropIfExists('provider_report_jobs'); }
};
