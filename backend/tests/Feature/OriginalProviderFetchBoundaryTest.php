<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\ProviderDeveloperConfig;
use App\Models\User;
use App\Services\Advertising\Adapters\GoogleAdsAdapter;
use App\Services\Advertising\Adapters\SnapchatAdsAdapter;
use App\Services\Advertising\Adapters\TikTokAdsAdapter;
use App\Services\Advertising\OAuthManager;
use App\Services\Advertising\Sync\AdDataSyncEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class OriginalProviderFetchBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    private function account(string $p): ConnectedAdAccount
    {
        return ConnectedAdAccount::create(['user_id' => User::factory()->create()->id, 'provider' => $p, 'external_account_id' => '123', 'account_name' => 'Synthetic', 'currency' => 'USD', 'timezone' => 'UTC', 'access_token' => 'fixture']);
    }

    private function adapter(string $p): object
    {
        $c = new ProviderDeveloperConfig(['provider' => $p, 'app_id' => 'fixture', 'app_secret' => 'fixture', 'developer_token' => 'fixture']);

        return match ($p) {
            'google' => new GoogleAdsAdapter($c),'tiktok' => new TikTokAdsAdapter($c),default => new SnapchatAdsAdapter($c)
        };
    }

    private function fake(callable $f): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($f);
    }

    private function row(string $p): array
    {
        return $p === 'google' ? ['campaign' => ['id' => 'c'], 'segments' => ['date' => '2026-11-01'], 'metrics' => ['costMicros' => 1000000, 'impressions' => 100, 'clicks' => 2, 'conversions' => 1, 'conversionsValue' => 2]] : ['dimensions' => ['campaign_id' => 'c', 'stat_time_day' => '2026-11-01'], 'metrics' => ['spend' => 1, 'impressions' => 100, 'clicks' => 2, 'conversion' => 1, 'total_purchase_value' => 2]];
    }

    public function test_google_and_tiktok_empty_intermediate_pages_continue_and_retain_raw(): void
    {
        foreach (['google', 'tiktok'] as $p) {
            $n = 0;
            $this->fake(function () use (&$n, $p) {
                $n++;

                return Http::response($p === 'google' ? ($n < 3 ? ['results' => [], 'nextPageToken' => 't'.$n] : ['results' => [$this->row($p)]]) : ['code' => 0, 'data' => ['list' => $n < 3 ? [] : [$this->row($p)], 'page_info' => ['page' => $n, 'total_page' => 3]]]);
            });
            $out = $this->adapter($p)->fetchInsights($this->account($p), '2026-11-01', '2026-11-01');
            $this->assertCount(1, $out['metrics']);
            $this->assertCount(3, $out['raw']);
            $this->assertSame('complete_local_traversal_not_inventory_verified', $out['fetch_status']);
            $this->assertSame(3, $n);
        }
    }

    public function test_paged_metrics_refuse_malformed_cycles_caps_duplicates_and_late_failure(): void
    {
        foreach (['google', 'tiktok'] as $p) {
            foreach (['missing', 'wronglist', 'badpage', 'cycle', 'cap', 'duplicate', 'latehttp', 'redirect'] as $mode) {
                $n = 0;
                $this->fake(function () use (&$n, $p, $mode) {
                    $n++;
                    if ($mode === 'redirect') {
                        return Http::response('', 302, ['Location' => 'https://example.invalid/collect']);
                    }if ($mode === 'latehttp' && $n === 2) {
                        return Http::response([], 400);
                    }if ($mode === 'missing') {
                        return Http::response(['code' => 0]);
                    }if ($p === 'google') {
                        return Http::response(['results' => $mode === 'wronglist' ? 'bad' : ($mode === 'duplicate' ? [$this->row($p)] : []), 'nextPageToken' => $mode === 'badpage' ? null : ($mode === 'cycle' ? 'same' : 't'.$n)]);
                    }

return Http::response(['code' => 0, 'data' => ['list' => $mode === 'wronglist' ? 'bad' : ($mode === 'duplicate' ? [$this->row($p)] : []), 'page_info' => ['page' => $mode === 'cycle' ? 1 : $n, 'total_page' => $mode === 'badpage' ? '2' : ($mode === 'cap' ? 101 : 2)]]]);
                });
                try {
                    $this->adapter($p)->fetchInsights($this->account($p), '2026-11-01', '2026-11-01');
                    $this->fail('Partial/malformed accepted '.$p.$mode);
                } catch (\RuntimeException) {
                    $this->assertTrue(true);
                }$this->assertLessThanOrEqual(100, $n);
            }
        }
    }

    public function test_hierarchy_failures_are_not_swallowed(): void
    {
        foreach (['google', 'tiktok'] as $p) {
            $this->fake(fn () => Http::response([], 400));
            try {
                $this->adapter($p)->fetchCampaigns($this->account($p));
                $this->fail('Hierarchy error swallowed');
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }Http::assertSentCount(1);
        }
    }

    public function test_tiktok_hierarchy_keeps_all_page_evidence_and_rejects_orphans(): void
    {
        foreach ([false, true] as $orphan) {
            $this->fake(function ($r) use ($orphan) {
                $list = str_contains($r->url(), '/adgroup/') ? [['campaign_id' => 'c', 'adgroup_id' => 'g']] : (str_contains($r->url(), '/ad/get/') ? [['ad_id' => 'a', 'adgroup_id' => $orphan ? 'foreign' : 'g']] : [['campaign_id' => 'c']]);

                return Http::response(['code' => 0, 'data' => ['list' => $list, 'page_info' => ['total_page' => 1]]]);
            });
            if ($orphan) {
                try {
                    $this->adapter('tiktok')->fetchCampaigns($this->account('tiktok'));
                    $this->fail('Orphan accepted');
                } catch (\RuntimeException) {
                    $this->assertTrue(true);
                }
            } else {
                $out = $this->adapter('tiktok')->fetchCampaigns($this->account('tiktok'));
                $this->assertCount(1, $out['campaigns'][0]['ad_sets'][0]['ads']);
                $this->assertCount(3, $out['raw']);
            }
        }
    }

    public function test_snapchat_requires_explicit_wrappers_and_refuses_duplicate_or_chunk_foreign_days(): void
    {
        foreach (['missing', 'wronglist', 'missingseries', 'duplicate', 'foreign', 'paging', 'failed'] as $mode) {
            $day = ['start_time' => $mode === 'foreign' ? '2026-11-02T00:00:00Z' : '2026-11-01T00:00:00Z', 'stats' => ['spend' => 0, 'impressions' => 0, 'swipes' => 0, 'conversion_purchases' => 0, 'conversion_purchases_value' => 0]];
            $series = ['timeseries' => $mode === 'duplicate' ? [$day, $day] : [$day]];
            if ($mode === 'missingseries') {
                $series = [];
            }$body = ['request_status' => 'SUCCESS', 'timeseries_stats' => [['sub_request_status' => $mode === 'failed' ? 'ERROR' : 'SUCCESS', 'timeseries_stat' => $series]]];
            if ($mode === 'missing') {
                unset($body['timeseries_stats']);
            }if ($mode === 'wronglist') {
                $body['timeseries_stats'] = 'bad';
            }if ($mode === 'paging') {
                $body['paging'] = ['next_link' => 'unverified'];
            }$this->fake(fn () => Http::response($body));
            try {
                $this->adapter('snapchat')->fetchInsights($this->account('snapchat'), '2026-11-01', '2026-11-01');
                $this->fail('Malformed Snapchat success '.$mode);
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_later_page_failure_keeps_old_metrics_and_last_success_unchanged(): void
    {
        foreach (['google', 'tiktok'] as $p) {
            $a = $this->account($p);
            $a->last_synced_at = '2026-10-31 12:00:00';
            $a->save();
            $old = PerformanceMetric::create(['connected_ad_account_id' => $a->id, 'provider' => $p, 'date' => '2026-10-31', 'currency' => 'USD', 'spend' => 7, 'impressions' => 7, 'clicks' => 1, 'conversions' => 1, 'conversion_value' => 8]);
            $old->refresh();
            $before = $old->getAttributes();
            $n = 0;
            $this->fake(function ($r) use ($p, &$n) {
                $isReport = $p === 'google' ? str_contains($r['query'] ?? '', 'metrics.cost_micros') : str_contains($r->url(), 'report/integrated');
                if (! $isReport) {
                    if ($p === 'google') {
                        return Http::response(['results' => str_contains($r['query'] ?? '', 'FROM ad_group_ad') ? [] : [['campaign' => ['id' => 'c']]]]);
                    }

return Http::response(['code' => 0, 'data' => ['list' => str_contains($r->url(), '/campaign/') ? [['campaign_id' => 'c']] : [], 'page_info' => ['total_page' => 1]]]);
                }$n++;
                if ($n > 1) {
                    return Http::response([], 400);
                }

return Http::response($p === 'google' ? ['results' => [$this->row($p)], 'nextPageToken' => 'next'] : ['code' => 0, 'data' => ['list' => [$this->row($p)], 'page_info' => ['total_page' => 2]]]);
            });
            $m = Mockery::mock(OAuthManager::class);
            $m->shouldReceive('getAdapter')->andReturn($this->adapter($p));
            $run = (new AdDataSyncEngine($m))->syncAccount($a, 'manual', '2026-11-01', '2026-11-01');
            $this->assertSame('failed', $run->status);
            $this->assertSame(1, PerformanceMetric::where('connected_ad_account_id', $a->id)->count());
            $this->assertSame($before, $old->fresh()->getAttributes());
            $this->assertSame('2026-10-31 12:00:00', $a->fresh()->last_synced_at->format('Y-m-d H:i:s'));
        }
    }

    public function test_discovery_refuses_missing_rosters_duplicates_and_unhandled_paging(): void
    {
        foreach (['google', 'tiktok', 'snapchat'] as $p) {
            foreach (['missing', 'duplicate', 'paging'] as $mode) {
                $this->fake(function () use ($p, $mode) {
                    return Http::response(match ($p) {
                        'google' => $mode === 'missing' ? [] : ['resourceNames' => $mode === 'duplicate' ? ['customers/123', 'customers/123'] : ['bad-resource']], 'tiktok' => ['code' => 0, 'data' => $mode === 'missing' ? [] : ($mode === 'paging' ? ['list' => [], 'page_info' => ['total_page' => 2]] : ['list' => [['advertiser_id' => '123'], ['advertiser_id' => '123']]])],default => ['request_status' => 'SUCCESS'] + ($mode === 'missing' ? [] : ($mode === 'paging' ? ['organizations' => [], 'paging' => ['next_link' => 'unhandled']] : ['organizations' => [['sub_request_status' => 'SUCCESS', 'organization' => ['id' => 'o', 'ad_accounts' => [['id' => 'a'], ['id' => 'a']]]]]]))
                    });
                });
                try {
                    $this->adapter($p)->discoverAccounts('fixture');
                    $this->fail('Discovery partial accepted '.$p.$mode);
                } catch (\RuntimeException) {
                    $this->assertTrue(true);
                }
            }
        }
    }

    public function test_snapchat_later_chunk_failure_never_writes_earlier_chunk(): void
    {
        $a = $this->account('snapchat');
        $n = 0;
        $this->fake(function () use (&$n) {
            $n++;

            return Http::response($n === 1 ? ['request_status' => 'SUCCESS', 'timeseries_stats' => [['sub_request_status' => 'SUCCESS', 'timeseries_stat' => ['timeseries' => [['start_time' => '2026-09-01T00:00:00Z', 'stats' => ['spend' => 0, 'impressions' => 0, 'swipes' => 0, 'conversion_purchases' => 0, 'conversion_purchases_value' => 0]]]]]]] : ['request_status' => 'ERROR']);
        });
        $m = Mockery::mock(OAuthManager::class);
        $m->shouldReceive('getAdapter')->andReturn($this->adapter('snapchat'));
        $run = (new AdDataSyncEngine($m))->syncAccount($a, 'manual', '2026-09-01', '2026-10-15');
        $this->assertSame('failed', $run->status);
        $this->assertSame(2, $n);
        $this->assertSame(0, PerformanceMetric::where('connected_ad_account_id', $a->id)->count());
        $this->assertNull($a->fresh()->last_synced_at);
    }

    public function test_google_hierarchy_token_cycle_and_orphan_campaign_fail(): void
    {
        foreach (['cycle', 'orphan'] as $mode) {
            $this->fake(function ($r) use ($mode) {
                if (str_contains($r['query'] ?? '', 'FROM ad_group_ad')) {
                    return Http::response($mode === 'cycle' ? ['results' => [], 'nextPageToken' => 'same'] : ['results' => [['campaign' => ['id' => 'foreign'], 'adGroup' => ['id' => 'g'], 'adGroupAd' => ['ad' => ['id' => 'a']]]]]);
                }

return Http::response(['results' => []]);
            });
            try {
                $this->adapter('google')->fetchCampaigns($this->account('google'));
                $this->fail('Invalid Google hierarchy accepted');
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_tiktok_changed_page_total_and_google_group_parent_conflict_refuse(): void
    {
        $n = 0;
        $this->fake(function () use (&$n) {
            $n++;

            return Http::response(['code' => 0, 'data' => ['list' => [], 'page_info' => ['page' => $n, 'total_page' => $n === 1 ? 2 : 3]]]);
        });
        try {
            $this->adapter('tiktok')->fetchInsights($this->account('tiktok'), '2026-11-01', '2026-11-01');
            $this->fail('Changed total accepted');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }$this->assertSame(2, $n);
        $this->fake(fn () => Http::response(['results' => [['campaign' => ['id' => 'c1'], 'adGroup' => ['id' => 'g'], 'adGroupAd' => ['ad' => ['id' => 'a1']]], ['campaign' => ['id' => 'c2'], 'adGroup' => ['id' => 'g'], 'adGroupAd' => ['ad' => ['id' => 'a2']]]]]));
        try {
            $this->adapter('google')->fetchCampaigns($this->account('google'));
            $this->fail('Group assigned two parents');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }
    }
}
