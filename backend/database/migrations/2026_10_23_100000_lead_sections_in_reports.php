<?php

declare(strict_types=1);

use App\Domain\Reporting\SectionKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A report can say how a client's recorded leads turned out.
 *
 * Like a store section, a lead section carries no metrics — it reads its own aggregates — so "at least one metric"
 * now applies to the other kinds only. The vocabulary is generated from the enum, as everywhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_kind_check');
        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_kind_check CHECK (kind IN ('%s'))",
            implode("','", SectionKind::values()),
        ));

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_has_metrics');
        DB::statement(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_has_metrics CHECK (\n"
            ."  kind IN ('store','lead') OR jsonb_array_length(metrics) > 0\n"
            .')'
        );
    }

    public function down(): void
    {
        DB::table('report_sections')->where('kind', SectionKind::Lead->value)->delete();

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_has_metrics');
        DB::statement(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_has_metrics CHECK (\n"
            ."  kind = 'store' OR jsonb_array_length(metrics) > 0\n"
            .')'
        );

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_kind_check');
        $earlier = array_values(array_filter(
            SectionKind::values(),
            static fn (string $kind): bool => $kind !== SectionKind::Lead->value,
        ));
        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_kind_check CHECK (kind IN ('%s'))",
            implode("','", $earlier),
        ));
    }
};
