<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normalised contact keys on store customers, so a person can be recognised across surfaces.
 *
 * Leads already carry these. Without the same columns here, linking an enquiry to an order would mean
 * normalising a phone number in SQL — which cannot be done correctly, because `0555001122` and `+966555001122`
 * are the same number and no amount of `lower()` will say so.
 *
 * The alternative was to normalise at read time in PHP, which would mean loading every customer of a client to
 * find one. Two indexed columns are cheaper and, more importantly, mean the leads module and the commerce
 * module recognise a person **the same way** — two modules normalising differently would be two modules that
 * disagree about how many people a client has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_customers', function (Blueprint $table): void {
            $table->string('email_key')->nullable()->after('email');
            $table->string('phone_key')->nullable()->after('phone');

            $table->index(['workspace_id', 'phone_key']);
            $table->index(['workspace_id', 'email_key']);
        });

        /*
         * Existing rows are deliberately left null rather than backfilled with a SQL approximation. A
         * half-normalised key would match some records and not others, which is worse than none at all: the
         * next sync recomputes them properly, and until then an unlinked journey is honestly unlinked rather
         * than inconsistently linked.
         */
    }

    public function down(): void
    {
        Schema::table('store_customers', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'phone_key']);
            $table->dropIndex(['workspace_id', 'email_key']);
            $table->dropColumn(['email_key', 'phone_key']);
        });
    }
};
