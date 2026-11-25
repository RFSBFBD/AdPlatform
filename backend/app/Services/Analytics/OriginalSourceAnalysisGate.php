<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\ConnectedAdAccount;
use Illuminate\Support\Facades\DB;

/** Prevent an older consumer bypassing original-source population/outcome admission using legacy rows. */
final class OriginalSourceAnalysisGate
{
    public function problem(int $ownerId, ?string $provider = null, ?int $accountId = null): ?string
    {
        $ids = ConnectedAdAccount::where('user_id', $ownerId)->when($provider, fn ($q) => $q->where('provider', $provider))
            ->when($accountId, fn ($q) => $q->where('id', $accountId))->pluck('id');
        if ($ids->isEmpty()) {
            return null;
        }
        if (DB::table('recorded_reporting_responses')->whereIn('connected_ad_account_id', $ids)->exists()
            || DB::table('recorded_delivery_snapshots')->whereIn('connected_ad_account_id', $ids)->exists()) {
            return 'Original reporting evidence exists in this scope, but complete source population and canonical outcome admission are not established. This legacy analysis cannot substitute rounded history or bypass the unified source gate.';
        }

        return null;
    }
}
