<?php

namespace Tests\Feature;

use App\Models\AccountBillingProfile;
use App\Models\BillingInvoice;
use App\Models\ConnectedAdAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected ConnectedAdAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'billing_tester@example.com'],
            ['name' => 'Billing Tester', 'password' => bcrypt('password123'), 'status' => 'active']
        );

        $this->account = ConnectedAdAccount::create([
            'user_id' => $this->user->id,
            'provider' => 'meta',
            'external_account_id' => 'act_bill_123',
            'account_name' => 'Meta Advertising Corp',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'access_token' => 'mock_bill_token',
            'status' => 'active',
        ]);

        AccountBillingProfile::create([
            'connected_ad_account_id' => $this->account->id,
            'outstanding_balance' => 350.50,
            'currency' => 'USD',
            'payment_method_type' => 'Credit Card',
            'payment_method_masked' => '•••• 9876',
            'status' => 'NORMAL',
        ]);

        BillingInvoice::create([
            'connected_ad_account_id' => $this->account->id,
            'external_invoice_id' => 'INV-META-2026-001',
            'invoice_date' => now()->format('Y-m-d'),
            'amount' => 1200.00,
            'tax_amount' => 50.00,
            'total_amount' => 1250.00,
            'currency' => 'USD',
            'status' => 'PAID',
            'download_url' => 'https://facebook.com/ads/manage/billing/receipt?id=INV-META-2026-001',
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/billing/summary')->assertStatus(401);
        $this->getJson('/api/v1/billing/invoices')->assertStatus(401);
    }

    public function test_billing_summary_returns_aggregated_balances_and_payment_methods(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/billing/summary');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'summary' => [
                    'total_outstanding_balance',
                    'invoiced_this_month',
                    'invoices_count_this_month',
                    'currency',
                ],
                'accounts' => [
                    '*' => [
                        'account_id', 'account_name', 'provider', 'outstanding_balance',
                        'payment_method_type', 'payment_method_masked', 'status'
                    ]
                ]
            ]);

        $data = $response->json();
        $this->assertGreaterThanOrEqual(350.50, $data['summary']['total_outstanding_balance']);
        $this->assertGreaterThanOrEqual(1250.00, $data['summary']['invoiced_this_month']);
    }

    public function test_billing_invoices_returns_paginated_list_with_receipt_url(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/billing/invoices');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'external_invoice_id', 'account_name', 'provider',
                        'invoice_date', 'amount', 'total_amount', 'status', 'download_url'
                    ]
                ],
                'pagination' => ['current_page', 'total'],
            ]);

        $item = collect($response->json('data'))->firstWhere('external_invoice_id', 'INV-META-2026-001');
        $this->assertNotNull($item);
        $this->assertEquals(1250.00, $item['total_amount']);
        $this->assertEquals('PAID', $item['status']);
        $this->assertStringContainsString('receipt?id=INV-META-2026-001', $item['download_url']);
    }
}
