<?php

declare(strict_types=1);

use App\Domain\Reporting\SectionKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A report can hold a clicks-and-funnel section and an attributed-revenue section.
 *
 * Neither carries metrics: the funnel is a fixed set of stages and attribution reads orders. Attribution names its
 * revenue authority in the same column a store section does, so that column now means something for exactly those two
 * kinds and for no other — the rule the store migration set, widened rather than loosened.
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
            ."  kind IN ('store','lead','creative','traffic','attribution') OR jsonb_array_length(metrics) > 0\n"
            .')'
        );

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_store_names_source');
        DB::statement(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_store_names_source CHECK (\n"
            ."  (kind IN ('store','attribution')) = (revenue_source IS NOT NULL)\n"
            .')'
        );
    }

    public function down(): void
    {
        DB::table('report_sections')->whereIn('kind', ['traffic', 'attribution'])->delete();

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_store_names_source');
        DB::statement(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_store_names_source CHECK (\n"
            ."  (kind = 'store') = (revenue_source IS NOT NULL)\n"
            .')'
        );

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_has_metrics');
        DB::statement(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_has_metrics CHECK (\n"
            ."  kind IN ('store','lead','creative') OR jsonb_array_length(metrics) > 0\n"
            .')'
        );

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_kind_check');
        $earlier = array_values(array_filter(
            SectionKind::values(),
            static fn (string $kind): bool => ! in_array($kind, ['traffic', 'attribution'], true),
        ));
        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_kind_check CHECK (kind IN ('%s'))",
            implode("','", $earlier),
        ));
    }
};
