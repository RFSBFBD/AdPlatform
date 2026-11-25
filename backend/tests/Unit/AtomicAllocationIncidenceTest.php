<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Decision\AtomicAllocationIncidence;
use PHPUnit\Framework\TestCase;

final class AtomicAllocationIncidenceTest extends TestCase
{
    private function fixture(): array
    {
        return ['snapshot_id' => 'synthetic', 'problem_id' => 'synthetic', 'problem' => ['variables' => [
            ['id' => 'a', 'platform' => 'alpha', 'entity_level' => 'campaign'], ['id' => 'b', 'platform' => 'alpha', 'entity_level' => 'campaign'], ['id' => 'c', 'platform' => 'beta', 'entity_level' => 'campaign']],
            'groups' => [['id' => 'ab', 'members' => ['a', 'b'], 'minimum' => '0', 'maximum' => '10'], ['id' => 'bc', 'members' => ['b', 'c'], 'minimum' => '1', 'maximum' => '11']]],
            'scenarios' => ['optimized' => ['allocation' => ['a' => '1.000001', 'b' => '2.000002', 'c' => '3.000003'], 'check' => ['status' => 'checked', 'spend_exact' => '6.000006']], 'custom' => ['check' => ['status' => 'rejected']]]];
    }

    public function test_platform_partition_conserves_exact_atomic_spend_without_summing_overlapping_groups(): void
    {
        $r = (new AtomicAllocationIncidence)->build($this->fixture());
        self::assertSame('computed_atomic_membership', $r['status']);
        $s = $r['scenarios']['optimized'];
        self::assertSame(['alpha' => '3.000003', 'beta' => '3.000003'], $s['platform_spend_exact']);
        self::assertSame('6.000006', $s['platform_partition_sum_exact']);
        self::assertSame('5.000005', $s['constraint_group_spend']['bc']['spend_exact']);
        self::assertTrue($s['constraint_group_spend']['bc']['within_bounds']);
        self::assertFalse($r['independent_parent_variables']);
        self::assertSame('unavailable', $r['scenarios']['custom']['status']);
        self::assertArrayNotHasKey('sum_of_group_totals', $s);
    }

    public function test_incorrect_checked_population_or_total_does_not_create_successful_rollup(): void
    {
        $r = $this->fixture();
        $r['scenarios']['optimized']['check']['spend_exact'] = '6.000005';
        self::assertSame('rejected_platform_partition_conservation', (new AtomicAllocationIncidence)->build($r)['scenarios']['optimized']['status']);
        unset($r['scenarios']['optimized']['allocation']['b']);
        self::assertSame('rejected_population_mismatch', (new AtomicAllocationIncidence)->build($r)['scenarios']['optimized']['status']);
    }
}
