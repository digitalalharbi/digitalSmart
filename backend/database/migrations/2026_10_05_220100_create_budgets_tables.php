<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets and their allocation tree.
 *
 * The governance hierarchy the product is built around: an approved client budget,
 * split across platforms, then campaigns, then ad groups, and compared with the actual
 * spend the sync records. The CHECK constraints below carry the rules the application
 * would otherwise have to remember on every write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            /*
             * A budget belongs to a client, always. A budget without a client is a
             * number nobody agreed to, and "whose money is this" is the first question
             * anyone asks about one.
             */
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            /*
             * The project is optional: an agency often agrees a client's quarterly
             * budget before deciding which campaigns will spend it. Nulling it on
             * project deletion keeps the budget and the spend recorded against it.
             */
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->date('period_start');
            $table->date('period_end');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status')->default('draft');

            // Internal, and never shown to a client: see docs/API.md.
            $table->text('notes')->nullable();

            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            /*
             * Who approved it and when, kept on the row as well as in the audit log.
             * The audit log answers "what changed"; this answers "is this figure
             * agreed", which every pacing calculation needs on every read.
             */
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'client_id', 'period_start']);
            $table->index(['workspace_id', 'project_id']);

            // Two budgets for the same client, period and name are indistinguishable.
            $table->unique(['workspace_id', 'client_id', 'name', 'period_start']);
        });

        /*
         * A period that ends before it starts would make every pacing figure
         * meaningless, and a budget of zero or less is not a budget.
         */
        DB::statement(
            'ALTER TABLE budgets ADD CONSTRAINT budgets_period_ordered CHECK (period_end >= period_start)'
        );
        DB::statement(
            'ALTER TABLE budgets ADD CONSTRAINT budgets_amount_positive CHECK (amount_minor > 0)'
        );
        DB::statement(
            "ALTER TABLE budgets ADD CONSTRAINT budgets_status_known CHECK (status IN ('draft', 'approved', 'archived'))"
        );
        /*
         * Approval is both or neither. A budget marked approved with nobody's name on
         * it is exactly the record this table exists to make impossible.
         */
        DB::statement(
            'ALTER TABLE budgets ADD CONSTRAINT budgets_approval_complete CHECK ('
            ."(status <> 'approved') OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))"
        );

        Schema::create('budget_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('budget_id')->constrained('budgets')->cascadeOnDelete();
            // Self-referencing: the constraint is added after the table exists, below.
            $table->uuid('parent_id')->nullable();

            $table->string('level');
            $table->string('provider');
            /*
             * The entities this allocation is about, where they are known. All nullable:
             * a plan is often written before the campaigns exist, and the allocation is
             * still useful — it is compared with spend by provider until then.
             */
            $table->foreignUuid('external_account_id')->nullable()->constrained('external_accounts')->nullOnDelete();
            $table->foreignUuid('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignUuid('ad_group_id')->nullable()->constrained('ad_groups')->nullOnDelete();

            $table->string('label');
            $table->bigInteger('amount_minor');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'budget_id', 'level']);
            $table->index(['budget_id', 'parent_id', 'position']);
            $table->index(['workspace_id', 'campaign_id']);
        });

        /*
         * Self-referencing, because the hierarchy is a tree: a campaign allocation sits
         * under a platform allocation and an ad group allocation under a campaign.
         * Cascading keeps a branch from outliving its root. Declared after creation
         * because a table cannot reference a primary key it does not have yet.
         */
        Schema::table('budget_allocations', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('budget_allocations')->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE budget_allocations ADD CONSTRAINT budget_allocations_amount_positive CHECK (amount_minor > 0)'
        );
        DB::statement(
            'ALTER TABLE budget_allocations ADD CONSTRAINT budget_allocations_level_known CHECK ('
            ."level IN ('platform', 'campaign', 'ad_group'))"
        );
        /*
         * A platform allocation is a root and the other two are not. Without this the
         * tree can grow sideways — a campaign allocation hanging off the budget
         * directly — and the sum of the platform level would stop being the sum of the
         * budget.
         */
        DB::statement(
            'ALTER TABLE budget_allocations ADD CONSTRAINT budget_allocations_parentage CHECK ('
            ."(level = 'platform' AND parent_id IS NULL) OR (level <> 'platform' AND parent_id IS NOT NULL))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_allocations');
        Schema::dropIfExists('budgets');
    }
};
