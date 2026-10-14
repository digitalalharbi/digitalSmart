<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whose credentials a connection holds, and the end of one-per-provider.
     *
     * The original table was unique on `(workspace_id, provider)`, with a comment arguing that two live
     * connections to one platform would make "which credentials synced this?" unanswerable. The argument was
     * right and the conclusion was too strong. An agency runs its own manager account *and* holds
     * credentials individual clients granted directly — a client who will not move their ad account under
     * the agency's Business Manager, or who intends to take it back at the end of the contract. Both are in
     * use on the same platform on the same day.
     *
     * The question the old index protected stays answerable, because every account, store and measurement
     * source already carries `provider_connection_id`. Provenance comes from the row, not from there being
     * only one candidate.
     *
     * Two partial indexes replace the one total index, and together they say the rule precisely: the agency
     * has at most one set of its own credentials per platform, and each client has at most one set of
     * theirs. A plain composite unique would not do it — Postgres treats NULLs as distinct, so every
     * agency-owned row would pass.
     */
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->string('owner_type')->default('agency')->after('provider');
            $table->foreignUuid('owner_client_id')->nullable()->after('owner_type')
                ->constrained('clients')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE provider_connections ADD CONSTRAINT provider_connections_owner_type_check CHECK (owner_type IN ('agency', 'client'))");

        /*
         * The type and the client agree, or the row does not exist. Without this a 'client' row with no
         * client id would read back as nobody's, and the domain would have to guess at a question that has
         * a right answer.
         */
        DB::statement("ALTER TABLE provider_connections ADD CONSTRAINT provider_connections_owner_coherent CHECK ((owner_type = 'client') = (owner_client_id IS NOT NULL))");

        DB::statement('ALTER TABLE provider_connections DROP CONSTRAINT provider_connections_workspace_id_provider_unique');

        DB::statement("CREATE UNIQUE INDEX provider_connections_agency_unique ON provider_connections (workspace_id, provider) WHERE owner_type = 'agency' AND deleted_at IS NULL");
        DB::statement("CREATE UNIQUE INDEX provider_connections_client_unique ON provider_connections (workspace_id, provider, owner_client_id) WHERE owner_type = 'client' AND deleted_at IS NULL");

        $table = 'provider_connections';
        DB::statement("CREATE INDEX {$table}_owner_client_index ON {$table} (owner_client_id) WHERE owner_client_id IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS provider_connections_owner_client_index');
        DB::statement('DROP INDEX IF EXISTS provider_connections_client_unique');
        DB::statement('DROP INDEX IF EXISTS provider_connections_agency_unique');
        DB::statement('ALTER TABLE provider_connections DROP CONSTRAINT IF EXISTS provider_connections_owner_coherent');
        DB::statement('ALTER TABLE provider_connections DROP CONSTRAINT IF EXISTS provider_connections_owner_type_check');

        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_client_id');
            $table->dropColumn('owner_type');
        });

        DB::statement('ALTER TABLE provider_connections ADD CONSTRAINT provider_connections_workspace_id_provider_unique UNIQUE (workspace_id, provider)');
    }
};
