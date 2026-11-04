<?php

declare(strict_types=1);

use App\Domain\Reporting\ReportIntent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A report states what it is for: detailed (everything defined) or executive (the headline view). Every existing
 * report stays detailed, so nothing already saved or shared changes what it shows. Reversible: the column is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->string('intent')->default(ReportIntent::Detailed->value);
        });

        DB::statement(sprintf(
            "ALTER TABLE reports ADD CONSTRAINT reports_intent_check CHECK (intent IN ('%s'))",
            implode("','", ReportIntent::values()),
        ));
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reports DROP CONSTRAINT reports_intent_check');
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('intent');
        });
    }
};
