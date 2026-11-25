<?php
use App\Models\ConnectedAdAccount; use App\Services\Advertising\Sync\RecordedDeliveryRepository;
chdir('/tmp/w/adplatform/backend'); require 'vendor/autoload.php';
$app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$account = ConnectedAdAccount::where('provider','google')->firstOrFail();
$snapshot = (new RecordedDeliveryRepository)->latestForAccount($account,(int)$account->user_id,'2026-11-01','2026-11-14');
$spec = array_intersect_key($snapshot['scope'], array_flip(['currency','outcome_definition','reporting_window','timezone','population','outcome_kind'])) + [
 'source_snapshot_id'=>$snapshot['snapshot_id'],'objective'=>'MAXIMIZE_CONVERSIONS','response_metric'=>'conversions','response_unit'=>'unavailable',
 'business_estimand'=>'no_canonical_outcome_selected','budget_semantics'=>'period_total_not_daily_rate','horizon_days'=>14,'forecast_horizon_days'=>1,'budget_cap'=>'10','minimum_spend'=>'0','minimum_response'=>'0','allocation_mode'=>'finite_menu','interaction_model'=>'separable_no_overlap_claim','groups'=>[],
 'variables'=>[['id'=>'campaign:111','platform'=>'google','campaign_type'=>'SEARCH','entity_level'=>'campaign','entity_id'=>'111','current_spend'=>'1.000001','minimum'=>'0','maximum'=>'10','menu'=>['0','10']]]];
echo json_encode(['account_id'=>$account->id,'start_date'=>'2026-11-01','end_date'=>'2026-11-14','problem'=>$spec]);
