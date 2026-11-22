<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Product cleanup (non-destructive): the invitation flow never had an accept endpoint or UI
 * (dead code), and the billing screens were removed from the product earlier while their
 * tables stayed unused and unwired.
 *
 * This migration does NOT drop anything and does NOT delete data. It renames the orphaned
 * tables to archive_* so the application schema is clean while every row stays in place.
 * Verify the archived tables, take a backup, and only then drop them manually if they are
 * truly unwanted. Rename back to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invitations') && ! Schema::hasTable('archive_invitations')) {
            Schema::rename('invitations', 'archive_invitations');
        }
        if (Schema::hasTable('billing_invoices') && ! Schema::hasTable('archive_billing_invoices')) {
            Schema::rename('billing_invoices', 'archive_billing_invoices');
        }
        if (Schema::hasTable('account_billing_profiles') && ! Schema::hasTable('archive_account_billing_profiles')) {
            Schema::rename('account_billing_profiles', 'archive_account_billing_profiles');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('archive_invitations') && ! Schema::hasTable('invitations')) {
            Schema::rename('archive_invitations', 'invitations');
        }
        if (Schema::hasTable('archive_billing_invoices') && ! Schema::hasTable('billing_invoices')) {
            Schema::rename('archive_billing_invoices', 'billing_invoices');
        }
        if (Schema::hasTable('archive_account_billing_profiles') && ! Schema::hasTable('account_billing_profiles')) {
            Schema::rename('archive_account_billing_profiles', 'account_billing_profiles');
        }
    }
};
