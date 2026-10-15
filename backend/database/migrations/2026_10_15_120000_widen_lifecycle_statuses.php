<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The lifecycle an agency actually runs.
     *
     * A project is *drafted*, *onboarded*, run, possibly *paused*, *closed down* and finished — and the two
     * states the product lacked, Onboarding and Closing, are exactly where most of the operational risk
     * sits: an onboarding project has accounts half-bound and tracking half-checked, and a closing one is
     * the last time anybody reads its figures before they go to a report.
     *
     * A client gains *Onboarding* and *Offboarded*. Offboarded is not archived: an offboarded client left
     * and may come back, and offboarding is the act that stops spending anything recurring on them while
     * keeping every figure, report and audit entry they produced.
     *
     * The stored value `planning` is kept and presented as "Draft": renaming a stored value would mean a
     * data migration and a break for anything holding the old one, in exchange for a word.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE projects DROP CONSTRAINT projects_status_check');
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status IN ('planning', 'onboarding', 'active', 'paused', 'closing', 'completed', 'archived'))");

        DB::statement('ALTER TABLE clients DROP CONSTRAINT clients_status_check');
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_status_check CHECK (status IN ('prospect', 'onboarding', 'active', 'paused', 'offboarded', 'archived'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE projects SET status = 'active' WHERE status = 'onboarding'");
        DB::statement("UPDATE projects SET status = 'paused' WHERE status = 'closing'");
        DB::statement('ALTER TABLE projects DROP CONSTRAINT projects_status_check');
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status IN ('planning', 'active', 'paused', 'completed', 'archived'))");

        DB::statement("UPDATE clients SET status = 'prospect' WHERE status = 'onboarding'");
        DB::statement("UPDATE clients SET status = 'archived' WHERE status = 'offboarded'");
        DB::statement('ALTER TABLE clients DROP CONSTRAINT clients_status_check');
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_status_check CHECK (status IN ('prospect', 'active', 'paused', 'archived'))");
    }
};
