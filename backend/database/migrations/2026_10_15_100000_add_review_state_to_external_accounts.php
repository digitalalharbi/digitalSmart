<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An operator's decision to leave a discovered account alone.
     *
     * Discovery finds everything a credential can see, and an agency MCC can see accounts that will never
     * be anybody's project — a client's old test account, a closed legacy one. Those must neither be bound
     * silently nor sit in the review queue forever, and they must not be deleted, because the next
     * discovery would simply find them again and the decision would be lost.
     *
     * So the decision is data: when, and by whom. An ignored account is out of the queue, still listed
     * under its own heading, and can be restored.
     */
    public function up(): void
    {
        Schema::table('external_accounts', function (Blueprint $table): void {
            $table->timestampTz('ignored_at')->nullable();
            $table->foreignUuid('ignored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['workspace_id', 'ignored_at']);
        });
    }

    public function down(): void
    {
        Schema::table('external_accounts', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'ignored_at']);
            $table->dropConstrainedForeignId('ignored_by');
            $table->dropColumn('ignored_at');
        });
    }
};
