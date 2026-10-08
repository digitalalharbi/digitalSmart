<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merging two leads a human knows are one person.
 *
 * **The absorbed lead is kept, not deleted.** Three reasons, and the third is the one that would have been
 * found the hard way:
 *
 * 1. A client may already have been told a lead count. A row that vanishes makes last month's report
 *    unreproducible, and "the number changed because somebody merged two records" is only an answer if the
 *    merge is written down somewhere.
 * 2. The history is the point of the leads tables. `lead_events` cascades from the lead, so deleting the
 *    absorbed row would delete the trail of everything that happened to it before anybody realised the two
 *    were one person.
 * 3. **The absorbed lead keeps its own `external_id`.** A CRM or form provider re-reading its own record
 *    looks the lead up by that id; if the row were gone the next sync would create a duplicate, and the
 *    merge would have to be done again after every sync. Keeping it means the re-read updates the row that
 *    already exists and the merge holds.
 *
 * Reads exclude a merged-away lead by `merged_into_id IS NULL`, so it counts once — on the survivor — and
 * the pair remains inspectable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            /*
             * Cascade rather than null-on-delete. If a survivor were ever deleted, nulling this would silently
             * return the absorbed lead to every count as though the merge had not happened — the one outcome
             * nobody could detect. Nothing in this product deletes a lead; a client deletion cascades to both.
             */
            $table->foreignUuid('merged_into_id')->nullable()->constrained('leads')->cascadeOnDelete();
            $table->timestamp('merged_at')->nullable();
            $table->foreignUuid('merged_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['workspace_id', 'merged_into_id']);
        });

        /*
         * A merge is one fact, so its three columns agree or the row is refused. A `merged_at` with no
         * survivor is a lead that reads as merged and counts as live.
         */
        DB::statement(
            'ALTER TABLE leads ADD CONSTRAINT leads_merge_is_whole CHECK ('
            .'(merged_into_id IS NULL) = (merged_at IS NULL))'
        );

        /** A lead merged into itself would disappear from every count and belong to nothing. */
        DB::statement(
            'ALTER TABLE leads ADD CONSTRAINT leads_merge_not_self CHECK ('
            .'merged_into_id IS NULL OR merged_into_id <> id)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE leads DROP CONSTRAINT leads_merge_not_self');
        DB::statement('ALTER TABLE leads DROP CONSTRAINT leads_merge_is_whole');

        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'merged_into_id']);
            $table->dropConstrainedForeignId('merged_by_id');
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn('merged_at');
        });
    }
};
