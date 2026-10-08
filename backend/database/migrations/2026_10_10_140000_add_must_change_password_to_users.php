<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A password an administrator has seen is not a password.
 *
 * This repository has no sender identity, so a new colleague cannot be emailed a link — the same block that
 * defers notification digests. The honest alternative is a one-time password the administrator reads out, and
 * the only thing that makes that acceptable is that it stops working the moment it is used: until the member
 * chooses their own, every request except reading their session, changing the password and signing out is
 * refused.
 *
 * Without this column the flow would be "an administrator knows your password indefinitely", which is not a flow
 * worth shipping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('must_change_password');
        });
    }
};
