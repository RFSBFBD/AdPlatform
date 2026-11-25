<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Services\Analytics\{ForecastingEngine,ResponseCurveFitter};
class AnalyticsInvalidInputTest extends TestCase
{
    public function test_forecast_does_not_compress_nonfinite_or_missing_days(): void
    {
        $s=array_fill(0,30,10);$s[8]=NAN;$r=(new ForecastingEngine())->forecast($s);$this->assertSame('invalid_data',$r['status']);$this->assertSame([],$r['points']);
        $s[8]=null;$this->assertSame('invalid_data',(new ForecastingEngine())->forecast($s)['status']);
    }
    public function test_anomaly_invalid_days_abstain_instead_of_shifting_flag_dates(): void { $s=range(1,30);$s[3]=NAN;$r=(new \App\Services\Analytics\AnomalyDetector())->analyze($s);$this->assertSame('invalid_data',$r['status']);$this->assertSame([],$r['flagged_points']); }
    public function test_invalid_interval_level_does_not_reach_quantile(): void { $this->assertSame('invalid_data',(new ForecastingEngine())->forecast(range(1,30),7,1.0)['status']); }
    public function test_fitter_abstains_on_negative_or_missing_response(): void
    {
        $s=array_map(fn($x)=>['spend'=>$x,'response'=>2*$x],range(1,30));$s[5]['response']=-1;$this->assertSame('invalid_data',(new ResponseCurveFitter())->fit($s)['status']);unset($s[5]['response']);$this->assertSame('invalid_data',(new ResponseCurveFitter())->fit($s)['status']);
    }
}
