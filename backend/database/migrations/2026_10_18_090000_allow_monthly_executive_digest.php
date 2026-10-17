<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE digests DROP CONSTRAINT digests_kind_check');
        DB::statement("ALTER TABLE digests ADD CONSTRAINT digests_kind_check CHECK (kind IN ('morning_campaigns','daily_budget','weekly_projects','monthly_executive'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM digests WHERE kind = 'monthly_executive'");
        DB::statement('ALTER TABLE digests DROP CONSTRAINT digests_kind_check');
        DB::statement("ALTER TABLE digests ADD CONSTRAINT digests_kind_check CHECK (kind IN ('morning_campaigns','daily_budget','weekly_projects'))");
    }
};
