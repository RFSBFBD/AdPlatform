<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccountBillingProfile;
use App\Models\BillingInvoice;
use App\Models\ConnectedAdAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    /**
     * Get billing summary: balances, payment methods, and current month invoice totals.
     */
    public function summary(Request $request): JsonResponse
    {
        // Tenancy / User Scoping (SEC-001)
        $accounts = ConnectedAdAccount::with('billingProfile')
            ->where('user_id', $request->user()->id)
            ->get();

        $accountIds = $accounts->pluck('id');
        $totalOutstanding = 0.00;
        $accountProfiles = [];

        foreach ($accounts as $account) {
            $profile = $account->billingProfile;
            $balance = $profile ? (float) $profile->outstanding_balance : 0.00;
            $totalOutstanding += $balance;

            $accountProfiles[] = [
                'account_id' => $account->id,
                'account_name' => $account->account_name,
                'provider' => $account->provider,
                'currency' => $account->currency,
                'outstanding_balance' => $balance,
                'payment_method_type' => $profile?->payment_method_type ?? 'Credit Card',
                'payment_method_masked' => $profile?->payment_method_masked ?? '•••• 4242',
                'status' => $profile?->status ?? 'NORMAL',
            ];
        }

        // Calculate invoices billed this current calendar month scoped to user's accounts
        $startOfMonth = Carbon::now()->startOfMonth()->format('Y-m-d');
        $endOfMonth = Carbon::now()->endOfMonth()->format('Y-m-d');

        $invoicedThisMonth = (float) BillingInvoice::whereIn('connected_ad_account_id', $accountIds)
            ->whereBetween('invoice_date', [$startOfMonth, $endOfMonth])
            ->where('status', 'PAID')
            ->sum('total_amount');

        $invoicesCount = BillingInvoice::whereIn('connected_ad_account_id', $accountIds)
            ->whereBetween('invoice_date', [$startOfMonth, $endOfMonth])
            ->count();

        return response()->json([
            'summary' => [
                'total_outstanding_balance' => round($totalOutstanding, 2),
                'invoiced_this_month' => round($invoicedThisMonth, 2),
                'invoices_count_this_month' => $invoicesCount,
                'currency' => 'USD',
            ],
            'accounts' => $accountProfiles,
        ]);
    }

    /**
     * Get paginated invoices with provider details and download links.
     */
    public function invoices(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'in:meta,google,tiktok,snapchat'],
            'status' => ['nullable', 'string', 'in:PAID,PENDING,FAILED,REFUNDED'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Tenancy / User Scoping (SEC-001)
        $userAccountIds = ConnectedAdAccount::where('user_id', $request->user()->id)->pluck('id');

        $query = BillingInvoice::with('connectedAdAccount:id,account_name,provider,currency')
            ->whereIn('connected_ad_account_id', $userAccountIds)
            ->orderBy('invoice_date', 'desc');

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['provider'])) {
            $query->whereHas('connectedAdAccount', function ($q) use ($validated) {
                $q->where('provider', $validated['provider']);
            });
        }

        $paginator = $query->paginate($validated['per_page'] ?? 15);

        $items = $paginator->getCollection()->map(function ($inv) {
            return [
                'id' => $inv->id,
                'external_invoice_id' => $inv->external_invoice_id,
                'account_name' => $inv->connectedAdAccount?->account_name,
                'provider' => $inv->connectedAdAccount?->provider,
                'invoice_date' => $inv->invoice_date->format('Y-m-d'),
                'amount' => (float) $inv->amount,
                'tax_amount' => (float) $inv->tax_amount,
                'total_amount' => (float) $inv->total_amount,
                'currency' => $inv->currency,
                'status' => $inv->status,
                'download_url' => $inv->download_url,
            ];
        });

        return response()->json([
            'data' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
