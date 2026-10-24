<?php

declare(strict_types=1);

use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A membership that ends is revoked, not deleted — and a workspace says what kind of organisation it is.
 *
 * Deleting the row lost who had been in a workspace, when, and who took them out; the audit kept a sentence, the data kept
 * nothing. A revoked membership keeps the row (and so the unique `(workspace_id, user_id)` pair): inviting the same person
 * again reactivates it rather than creating a second membership. It grants nothing, like a suspended one.
 *
 * The workspace type (agency, brand, freelancer, internal team) changes defaults and wording later. It never changes what a
 * person may do or see: permissions and isolation are the same for every type.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE workspace_members DROP CONSTRAINT workspace_members_status_check');
        DB::statement(sprintf(
            "ALTER TABLE workspace_members ADD CONSTRAINT workspace_members_status_check CHECK (status IN ('%s'))",
            implode("','", array_map(static fn (MembershipStatus $s): string => $s->value, MembershipStatus::cases())),
        ));

        Schema::table('workspace_members', function (Blueprint $table): void {
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::statement("ALTER TABLE workspace_members ADD CONSTRAINT workspace_members_revoked_check CHECK ((status = 'revoked') = (revoked_at IS NOT NULL))");

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('type', 16)->default(WorkspaceType::Agency->value);
        });
        DB::statement(sprintf(
            "ALTER TABLE workspaces ADD CONSTRAINT workspaces_type_check CHECK (type IN ('%s'))",
            implode("','", WorkspaceType::values()),
        ));
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workspaces DROP CONSTRAINT workspaces_type_check');
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('type');
        });

        /* The narrower vocabulary has no word for revoked; those memberships go, as they did before. */
        DB::statement('ALTER TABLE workspace_members DROP CONSTRAINT workspace_members_revoked_check');
        DB::table('workspace_members')->where('status', 'revoked')->delete();
        Schema::table('workspace_members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn('revoked_at');
        });

        DB::statement('ALTER TABLE workspace_members DROP CONSTRAINT workspace_members_status_check');
        DB::statement("ALTER TABLE workspace_members ADD CONSTRAINT workspace_members_status_check CHECK (status IN ('invited', 'active', 'suspended'))");
    }
};
