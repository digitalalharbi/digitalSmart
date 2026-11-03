<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A report link can be paused and resumed at the same address.
 *
 * Revoking is final and needs a reason; pausing is reversible and keeps the token, so the URL a client already holds
 * starts working again without a new credential. `paused_at` is null for a link that is on. It is checked after
 * revocation and expiry, so pausing never makes a dead link look resumable, and resuming never extends an expiry.
 * Additive and reversible; no existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_shares', function (Blueprint $table): void {
            $table->timestamp('paused_at')->nullable()->after('revoked_reason');
        });
    }

    public function down(): void
    {
        Schema::table('report_shares', function (Blueprint $table): void {
            $table->dropColumn('paused_at');
        });
    }
};
