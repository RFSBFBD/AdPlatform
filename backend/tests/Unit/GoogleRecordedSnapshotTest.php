<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Advertising\Sync\GoogleRecordedSnapshotBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GoogleRecordedSnapshotTest extends TestCase
{
    private function context(): array
    {
        return ['provider' => 'google', 'account_id' => '1', 'account_external_id' => '123', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo',
            'start_date' => '2026-11-01', 'end_date' => '2026-11-02', 'metadata_marker' => 'provider-discovery-explicit-1',
            'roster_status' => 'complete_local_traversal_not_inventory_verified', 'campaign_ids' => ['11', '22']];
    }

    private function page(string $body, ?string $token = null): array
    {
        $context = $this->context();

        return ['body' => $body, 'sha256' => hash('sha256', $body), 'provenance' => [
            'retrieved_at' => '2026-11-03T12:00:00+02:00', 'request_id' => 'fixture-request', 'account_external_id' => $context['account_external_id'], 'currency' => 'EGP', 'timezone' => 'Africa/Cairo', 'start_date' => '2026-11-01', 'end_date' => '2026-11-02', 'status' => 200,
            'request' => ['endpoint' => '/customers/123/googleAds:search', 'api_version' => 'v26', 'page_token' => $token,
                'query' => "SELECT campaign.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '2026-11-01' AND '2026-11-02'"]]];
    }

    public function test_two_page_snapshot_retains_exact_delivery_and_unknowns_without_zero_population_fill(): void
    {
        $pages = [$this->page('{"results":[{"campaign":{"id":"11"},"segments":{"date":"2026-11-01"},"metrics":{"costMicros":"9007199254740993","clicks":"1","impressions":"3"}}],"nextPageToken":"second"}'),
            $this->page('{"results":[{"campaign":{"id":"22"},"segments":{"date":"2026-11-02"},"metrics":{"costMicros":"7","clicks":"2"}}]}', 'second')];
        $result = (new GoogleRecordedSnapshotBuilder)->build($pages, $this->context())->jsonSerialize();
        self::assertSame('9007199254.741000', $result['totals']['spend']);
        self::assertSame('3', $result['totals']['clicks']);
        self::assertNull($result['totals']['impressions']);
        self::assertNull($result['totals']['conversions']);
        self::assertNull($result['totals']['conversion_value']);
        self::assertCount(2, $result['rows']);
        self::assertSame('returned_campaign_date_rows_only_not_complete_inventory', $result['scope']['population']);
        self::assertFalse($result['operational_approval']);
        self::assertSame('2026-11-03T12:00:00+02:00', $result['scope']['source_lineage']['pages'][0]['retrieved_at']);
        self::assertSame('fixture-request', $result['scope']['source_lineage']['pages'][1]['request_id']);
        self::assertSame(hash('sha256', 'second'), $result['scope']['source_lineage']['pages'][1]['incoming_page_token_sha256']);
        self::assertSame($pages[0]['sha256'], $result['scope']['source_lineage']['pages'][0]['body_sha256']);
        $later = $pages;
        $later[0]['provenance']['retrieved_at'] = '2026-11-04T12:00:00+02:00';
        self::assertNotSame($result['snapshot_id'], (new GoogleRecordedSnapshotBuilder)->build($later, $this->context())->jsonSerialize()['snapshot_id']);
    }

    public function test_incomplete_changed_repeated_duplicate_tampered_and_other_scope_pages_are_rejected(): void
    {
        $body = '{"results":[{"campaign":{"id":"11"},"segments":{"date":"2026-11-01"},"metrics":{"costMicros":"1","clicks":"0","impressions":"0"}}]}';
        $valid = $this->page($body);
        $variants = [];
        foreach ([null, '2026-02-30T12:00:00+02:00', 'no-timezone', '2026-11-03T25:00:00+02:00'] as $retrieval) {
            $bad = $valid;
            $bad['provenance']['retrieved_at'] = $retrieval;
            $variants[] = [[$bad], $this->context()];
        }
        $bad = $valid;
        $bad['provenance']['request_id'] = [];
        $variants[] = [[$bad], $this->context()];
        $bad = $valid;
        $bad['body'] .= ' ';
        $variants[] = [[$bad], $this->context()];
        foreach (['currency' => 'USD', 'timezone' => 'UTC', 'account_external_id' => '456', 'start_date' => '2026-10-01'] as $key => $value) {
            $bad = $valid;
            $bad['provenance'][$key] = $value;
            $variants[] = [[$bad], $this->context()];
        }
        $bad = $valid;
        $bad['provenance']['request']['api_version'] = 'v21';
        $variants[] = [[$bad], $this->context()];
        $bad = $valid;
        $bad['provenance']['request']['page_token'] = 'not-first';
        $variants[] = [[$bad], $this->context()];
        $first = $this->page(substr($body, 0, -1).',"nextPageToken":"next"}');
        $variants[] = [[$first], $this->context()];
        $second = $this->page($body, 'wrong');
        $variants[] = [[$first, $second], $this->context()];
        $second = $this->page($body, 'next');
        $variants[] = [[$first, $second], $this->context()];
        $repeat = $this->page(substr($body, 0, -1).',"nextPageToken":"next"}', 'next');
        $variants[] = [[$first, $repeat], $this->context()];
        $variants[] = [[$valid, $valid], $this->context()];
        $context = $this->context();
        $context['campaign_ids'] = ['22'];
        $variants[] = [[$valid], $context];
        $context = $this->context();
        $context['metadata_marker'] = 'legacy';
        $variants[] = [[$valid], $context];
        $context = $this->context();
        $context['roster_status'] = 'partial';
        $variants[] = [[$valid], $context];
        foreach ([['11', '11'], ['11', 22], ['bad']] as $roster) {
            $context = $this->context();
            $context['campaign_ids'] = $roster;
            $variants[] = [[$valid], $context];
        }
        foreach ($variants as [$pages,$context]) {
            try {
                (new GoogleRecordedSnapshotBuilder)->build($pages, $context);
                self::fail('unsafe page batch accepted');
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }
}
