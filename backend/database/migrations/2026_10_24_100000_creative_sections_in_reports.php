<?php

declare(strict_types=1);

use App\Domain\Reporting\CreativeView;
use App\Domain\Reporting\SectionKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A report can show how a client's creatives performed.
 *
 * Like a store or lead section, a creative section carries no metric list — it reads a fixed set from the creative
 * layer — and instead names which view of it it shows. Both vocabularies are generated from their enums.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE report_sections ADD COLUMN creative_view varchar(32) NULL');

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_kind_check');
        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_kind_check CHECK (kind IN ('%s'))",
            implode("','", SectionKind::values()),
        ));

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_has_metrics');
        DB::statement(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_has_metrics CHECK (\n"
            ."  kind IN ('store','lead','creative') OR jsonb_array_length(metrics) > 0\n"
            .')'
        );

        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_creative_view_check CHECK (\n"
            ."  (kind = 'creative') = (creative_view IS NOT NULL)\n"
            ."  AND (creative_view IS NULL OR creative_view IN ('%s'))\n"
            .')',
            implode("','", CreativeView::values()),
        ));
    }

    public function down(): void
    {
        DB::table('report_sections')->where('kind', SectionKind::Creative->value)->delete();

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_creative_view_check');

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_has_metrics');
        DB::statement(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_has_metrics CHECK (\n"
            ."  kind IN ('store','lead') OR jsonb_array_length(metrics) > 0\n"
            .')'
        );

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_kind_check');
        $earlier = array_values(array_filter(
            SectionKind::values(),
            static fn (string $kind): bool => $kind !== SectionKind::Creative->value,
        ));
        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_kind_check CHECK (kind IN ('%s'))",
            implode("','", $earlier),
        ));

        DB::statement('ALTER TABLE report_sections DROP COLUMN creative_view');
    }
};
