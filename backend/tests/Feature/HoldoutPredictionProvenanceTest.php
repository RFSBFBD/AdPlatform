<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Services\Analytics\ResponseCurveFitter;
final class HoldoutPredictionProvenanceTest extends TestCase{
 public function test_holdout_predictions_are_not_in_sample_full_refit_values():void{
  config(['analytics.curve_fit.complexity_penalty'=>100,'analytics.curve_fit.instability_weight'=>0,'analytics.curve_fit.max_validation_rmse_rel'=>1]);$points=[];for($i=1;$i<=30;$i++)$points[]=['spend'=>10*$i,'response'=>10*$i*($i<=21?2:2.2)];$r=(new ResponseCurveFitter)->fit($points);$this->assertSame('ok',$r['status']);$this->assertSame('linear',$r['model']);foreach($r['validation']['holdout_predictions']as$p){$this->assertEqualsWithDelta(2*$p['daily_spend'],$p['predicted_response'],1e-10);$this->assertNotEqualsWithDelta($r['curve']->revenueAt($p['daily_spend']),$p['predicted_response'],.01);}$this->assertGreaterThan(2,$r['params']['a']);
 }
}
