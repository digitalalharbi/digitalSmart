<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which project a campaign belongs to, when the bindings cannot say.
     *
     * A campaign's project has always been derived: campaign → external account → bindings → project. That
     * derivation is exact while an account serves one project, and `project_integration_bindings` is unique
     * on `(project_id, external_account_id)` precisely so an account *can* serve several — an agency
     * running Brand Awareness, Lead Generation and a seasonal push out of one Google Ads account is the
     * normal case, not an edge one. Every campaign on such an account had an ambiguous owner, and nothing
     * in the product said so.
     *
     * Nullable, and it stays null until somebody sets it. Null does not mean "no project": it means the
     * derivation has not been overridden, which resolves to the single bound project where there is one and
     * to *unassigned* where there is not. Defaulting it during sync would be exactly the silent binding the
     * constitution forbids — and a guess made from a campaign's name, which is the only other signal
     * available, is the one inference that is explicitly ruled out.
     *
     * The sync upsert lists its columns explicitly and this is not among them, so a re-sync cannot undo an
     * operator's assignment.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->foreignUuid('project_id')->nullable()->after('external_account_id')
                ->constrained('projects')->nullOnDelete();

            // The Campaign Control Center filters by project inside a workspace.
            $table->index(['workspace_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'project_id']);
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
