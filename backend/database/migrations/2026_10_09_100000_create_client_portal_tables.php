<?php

declare(strict_types=1);

use App\Domain\Portal\PortalModule;
use App\Domain\Portal\PortalState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The client portal: which client may read what, and who decided it.
 *
 * Four rules are in the schema rather than in a service, because each has a wrong version that is invisible once
 * the data is in.
 *
 *  - **A portal row means configured.** `PortalState::NotConfigured` has no representation here: the absence of a
 *    row *is* that state. A nullable state column would allow a third thing — a configured portal in no state —
 *    and whichever code path created it would be the one nobody tested.
 *  - **A module row means currently exposed.** Withdrawing a module deletes the row. The history of who exposed
 *    what and when lives in the audit trail, which is permanent, queryable and already the answer to "who let
 *    them see that" — a second soft-deleted copy here would be a second answer to the same question, and the two
 *    would disagree the first time a backfill touched one of them.
 *  - **Suspension carries its reason**, as a CHECK on both directions. A portal switched off with no reason is a
 *    row nobody can act on, and a reason left behind on a resumed portal reads as a current one.
 *  - **One portal per client**, as a unique constraint. Two rows would mean two answers to "can this client read
 *    their reports", and the query that happened to order differently would win.
 *
 * The module vocabulary is a CHECK generated from `PortalModule`, so a surface this product has decided not to
 * expose — see `WithheldModule` — cannot be exposed by a payload with a typo in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            /*
             * Cascade, and unique. A deleted client has no portal, and a client with two portals has two answers
             * to every disclosure question.
             */
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();

            $table->string('state')->default(PortalState::Active->value);

            $table->timestampTz('enabled_at');
            $table->foreignUuid('enabled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('suspended_at')->nullable();
            $table->foreignUuid('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            /** The agency's own note. Never shown to the client — see `PortalState::visitorMessage()`. */
            $table->text('suspended_reason')->nullable();

            $table->timestamps();

            $table->unique('client_id');
            $table->index(['workspace_id', 'state']);
        });

        DB::statement(sprintf(
            "ALTER TABLE client_portals ADD CONSTRAINT client_portals_state_check CHECK (state IN ('%s'))",
            implode("','", [PortalState::Active->value, PortalState::Suspended->value]),
        ));

        /*
         * Both directions. Suspended with no reason is a row nobody can act on; a reason on an active portal reads
         * as a current one to whoever opens the record next.
         */
        DB::statement(
            'ALTER TABLE client_portals ADD CONSTRAINT client_portals_suspension_complete CHECK ('."\n"
            ."    (state = '".PortalState::Suspended->value."')"."\n"
            ."    = (suspended_at IS NOT NULL AND suspended_reason IS NOT NULL AND btrim(suspended_reason) <> '')"."\n"
            .')',
        );

        Schema::create('client_portal_modules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('client_portal_id')->constrained('client_portals')->cascadeOnDelete();

            $table->string('module');

            $table->timestampTz('exposed_at');
            $table->foreignUuid('exposed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /** A module is exposed to a client once or not at all. */
            $table->unique(['client_portal_id', 'module']);
        });

        DB::statement(sprintf(
            'ALTER TABLE client_portal_modules ADD CONSTRAINT client_portal_modules_module_check '
            ."CHECK (module IN ('%s'))",
            implode("','", PortalModule::values()),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_modules');
        Schema::dropIfExists('client_portals');
    }
};
