<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\User;
use App\Services\OperationsResearch\AdvertisingOptimizationService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Database rows for the common 30-complete-day window: today, yesterday, old data, gaps. */
class OrWindowDbTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleAndPermissionSeeder())->run();
        $this->user = User::create(['name' => 'OR Window', 'email' => 'orwin_' . uniqid() . '@example.com', 'password' => bcrypt('x-test-only-1'), 'status' => 'active']);
        foreach (['meta', 'google'] as $p) {
            $this->acc[$p] = ConnectedAdAccount::create([
                'user_id' => $this->user->id, 'provider' => $p, 'external_account_id' => 'act_' . $p . uniqid(),
                'account_name' => $p, 'currency' => 'USD', 'timezone' => 'UTC', 'access_token' => 'tok', 'status' => 'connected',
            ]);
        }
    }

    /** rows for $provider on days (today - $fromAgo) .. (today - $toAgo) inclusive, skipping $skipAgo */
    private function rows(string $provider, int $fromAgo, int $toAgo, array $skipAgo = []): void
    {
        for ($a = $fromAgo; $a >= $toAgo; $a--) {
            if (in_array($a, $skipAgo, true)) {
                continue;
            }
            PerformanceMetric::create(['metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>$provider,'count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']],
                'connected_ad_account_id' => $this->acc[$provider]->id, 'provider' => $provider, 'currency' => 'USD',
                'date' => now()->subDays($a)->toDateString(), 'spend' => $provider === 'meta' ? 150.0 : 130.0,
                'impressions' => 1000, 'clicks' => 50, 'conversions' => 5, 'conversion_value' => $provider === 'meta' ? 450.0 : 600.0,
            ]);
        }
    }

    private function solve(): \App\Services\OperationsResearch\DTOs\OptimizationResultDto
    {
        return (new AdvertisingOptimizationService())->optimizeForUser($this->user->id, ['budget' => 8000.0, 'budget_horizon' => 'per_30_days', 'saturation_multiplier' => 2.5]);
    }

    public function test_complete_dates_do_not_authorize_cross_provider_outcome_pooling(): void
    {
        $this->rows('meta', 30, 1);
        $this->rows('google', 30, 1);
        $this->rows('meta', 0, 0); // today's partial row must be ignored
        $r = $this->solve();
        $this->assertSame('incomparable_metric_definitions', $r->status);
        $this->assertSame([], $r->channelAllocations);
    }

    public function test_staleness_alignment_does_not_authorize_cross_provider_outcomes(): void
    {
        $this->rows('meta', 31, 1);
        $this->rows('google', 32, 2); // allowed by the staleness gate (3 days)
        $r = $this->solve();
        $this->assertSame('incomparable_metric_definitions', $r->status);
        $this->assertSame([], $r->channelAllocations);
    }

    public function test_gaps_do_not_hide_cross_provider_semantic_refusal(): void
    {
        $this->rows('meta', 30, 1, [10]);
        $this->rows('google', 30, 1);
        $r = $this->solve();
        $this->assertSame('incomparable_metric_definitions', $r->status);
        $this->assertSame([], $r->channelAllocations);
    }

    public function test_old_data_beyond_staleness_gate_leaves_no_window(): void
    {
        $this->rows('meta', 40, 10);
        $this->rows('google', 40, 10);
        $r = $this->solve();
        $this->assertSame('insufficient_data', $r->status);
        $this->assertSame([], $r->channelAllocations);
    }

    public function test_one_stale_channel_does_not_drag_the_window_of_fresh_ones(): void
    {
        $this->rows('meta', 30, 1);
        $this->rows('google', 45, 15); // stale: excluded by the staleness gate, window ends at meta's last day
        $r = $this->solve();
        $this->assertSame('incomparable_metric_definitions', $r->status); // Different source histories are not equivalent, including stale data.
        $this->assertSame([], $r->channelAllocations);
    }
}
