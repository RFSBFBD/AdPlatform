<?php

declare(strict_types=1);

namespace App\Services\Decision\Accounting;

use App\Services\Decision\FrozenLinkedFunnel;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Immutable precision-preserving arithmetic snapshot, not proof of provider truth or completeness. */
final class RecordedLedger implements \JsonSerializable
{
    public const METRICS = ['spend', 'impressions', 'clicks', 'conversions', 'conversion_value'];

    private readonly array $record;

    /** A declared record envelope, not a provider adapter or an authorization/verification bypass. */
    public static function fromRecordedJson(array $scope, string $json, ?array $linkedFunnel = null): self
    {
        $decoded = RecordedJson::decode($json);
        if (! array_is_list($decoded)) {
            throw new InvalidArgumentException('recorded_row_list_required');
        }

        return new self($scope, $decoded, $json, $linkedFunnel);
    }

    public function __construct(array $scope, array $rows, ?string $originalJson = null, ?array $linkedFunnel = null)
    {
        $required = ['provider', 'account_id', 'source_contract', 'currency', 'timezone', 'reporting_window',
            'outcome_definition', 'outcome_kind', 'attribution', 'population', 'conversion_count_kind', 'provenance_kind'];
        foreach ($required as $key) {
            if (! isset($scope[$key]) || ! is_string($scope[$key]) || $scope[$key] === '') {
                throw new InvalidArgumentException('ledger_scope_required:'.$key);
            }
        }
        if (! in_array($scope['outcome_kind'], ['purchase', 'lead', 'qualified_lead', 'other_explicit'], true)) {
            throw new InvalidArgumentException('unsupported_explicit_outcome_kind');
        }
        if (! in_array($scope['timezone'], \DateTimeZone::listIdentifiers(), true) || ! preg_match('/^[A-Z]{3}$/D', $scope['currency'])) {
            throw new InvalidArgumentException('explicit_valid_currency_and_iana_timezone_required');
        }
        if (! in_array($scope['conversion_count_kind'], ['integral', 'fractional_credit'], true)
            || ! in_array($scope['provenance_kind'], ['synthetic_fixture', 'recorded_source'], true)) {
            throw new InvalidArgumentException('unsupported_count_kind_or_provenance');
        }
        if ($rows === [] || count($rows) > 100000) {
            throw new InvalidArgumentException('ledger_rows_missing_or_resource_limit');
        }
        if ($originalJson !== null && self::canonical(RecordedJson::decode($originalJson)) !== self::canonical($rows)) {
            throw new InvalidArgumentException('original_envelope_rows_mismatch');
        }
        $totals = array_fill_keys(self::METRICS, BigDecimal::zero());
        $evidence = [];
        $entities = [];
        $references = [];
        $missing = [];
        $entityMissing = [];
        $canonicalScope = self::canonical($scope);
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['key'], $row['source_ref'], $row['scope'])
                || ! is_string($row['key']) || $row['key'] === ''
                || ! is_string($row['source_ref']) || $row['source_ref'] === ''
                || ! isset($row['variable_id']) || ! is_string($row['variable_id']) || $row['variable_id'] === ''
                || ($row['scope'] !== $scope && self::canonical($row['scope']) !== $canonicalScope)) {
                throw new InvalidArgumentException('ledger_row_identity_source_or_scope_mismatch');
            }
            if (isset($evidence[$row['key']])) {
                throw new InvalidArgumentException('duplicate_ledger_row');
            }
            if (isset($references[$row['source_ref']])) {
                throw new InvalidArgumentException('duplicate_source_contribution');
            }
            $references[$row['source_ref']] = true;
            $values = [];
            foreach (self::METRICS as $metric) {
                if (! array_key_exists($metric, $row) || $row[$metric] === null) {
                    if ($metric === 'spend') {
                        throw new InvalidArgumentException('required_spend_missing');
                    }
                    $values[$metric] = ['lexeme' => null, 'decimal' => null, 'source_scale' => null, 'precision' => 'unavailable', 'reason' => 'missing_not_zero'];
                    $missing[$metric] = true;
                    $entityMissing[$row['variable_id']][$metric] = true;

                    continue;
                }

                $count = in_array($metric, ['clicks', 'impressions'], true)
                    || $metric === 'conversions' && $scope['conversion_count_kind'] === 'integral';
                $value = ExactValue::of($row[$metric] ?? null, $count);
                $values[$metric] = $value->jsonSerialize();
                $totals[$metric] = $totals[$metric]->plus($value->decimal());
                $entities[$row['variable_id']][$metric] = ($entities[$row['variable_id']][$metric] ?? BigDecimal::zero())->plus($value->decimal());
            }
            $evidence[$row['key']] = ['variable_id' => $row['variable_id'], 'source_ref' => $row['source_ref'], 'reporting_date' => $row['reporting_date'] ?? null, 'metrics' => $values];
        }
        ksort($evidence, SORT_STRING);
        ksort($entities, SORT_STRING);
        foreach ($entities as $id => &$entity) {
            foreach (self::METRICS as $metric) {
                $entity[$metric] = isset($entityMissing[$id][$metric]) ? null : (string) ($entity[$metric] ?? BigDecimal::zero());
            }
        }
        unset($entity);
        foreach ($totals as $metric => &$total) {
            $total = isset($missing[$metric]) ? null : (string) $total;
        } unset($total);
        $ratios = [];
        foreach (['ctr' => ['clicks', 'impressions', 1], 'cpc' => ['spend', 'clicks', 1],
            'cpm' => ['spend', 'impressions', 1000], 'cvr' => ['conversions', 'clicks', 1],
            'cpa' => ['spend', 'conversions', 1], 'roas' => ['conversion_value', 'spend', 1]] as $name => [$num, $den, $scale]) {
            if ($totals[$num] === null || $totals[$den] === null) {
                $ratios[$name] = ['type' => 'ACTUAL', 'exact' => null, 'reason' => 'missing_operand_not_zero', 'formula' => [$num, $den, (string) $scale]];

                continue;
            }
            $denominator = BigDecimal::of($totals[$den]);
            $ratios[$name] = ['type' => 'ACTUAL', 'exact' => $denominator->isZero() ? null : ExactValue::fraction(
                BigDecimal::of($totals[$num])->multipliedBy($scale)->toBigRational()->dividedBy($denominator)),
                'reason' => $denominator->isZero() ? 'undefined_zero_denominator' : null,
                'formula' => [$num, $den, (string) $scale]];
        }
        $body = ['source_envelope_json' => $originalJson, 'source_envelope_sha256' => $originalJson === null ? null : hash('sha256', $originalJson),
            'policy' => ExactValue::POLICY, 'scope' => $scope, 'rows' => $evidence, 'totals' => $totals,
            'entity_totals' => $entities, 'ratios' => $ratios, 'arithmetic' => 'exact_recorded_tokens',
            'source_truth' => 'unverified_by_arithmetic', 'completeness' => 'not_established_by_sum',
            'deduplication' => 'not_established', 'operational_approval' => false];
        if ($linkedFunnel !== null) {
            (new FrozenLinkedFunnel)->aggregate($scope, $linkedFunnel);
            $body['linked_funnel'] = $linkedFunnel;
        }
        $this->record = ['snapshot_id' => hash('sha256', self::canonical($body))] + $body;
    }

    public static function canonical(mixed $v): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                // JSON decoding turns numeric -0 into integer0. Canonicalize numeric zero only; recorded string lexemes are untouched.
                return is_float($item) && $item == 0.0 ? 0 : $item;
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($v), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function jsonSerialize(): array
    {
        return $this->record;
    }
}
