<?php

declare(strict_types=1);

use App\Domain\Reporting\SectionKind;
use App\Domain\Reporting\TrackingView;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A report can hold a view of a client's first-party tracking: link performance, form performance, the funnel, lead
 * follow-up and tracking health. It carries no metrics (each view is a fixed table) and names its view.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE report_sections ADD COLUMN tracking_view varchar(16) NULL');

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_kind_check');
        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_kind_check CHECK (kind IN ('%s'))",
            implode("','", SectionKind::values()),
        ));

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_has_metrics');
        DB::statement(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_has_metrics CHECK (\n"
            ."  kind IN ('store','lead','creative','traffic','attribution','tracking') OR jsonb_array_length(metrics) > 0\n"
            .')'
        );

        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_tracking_view_check CHECK (\n"
            ."  (kind = 'tracking') = (tracking_view IS NOT NULL)\n"
            ."  AND (tracking_view IS NULL OR tracking_view IN ('%s'))\n"
            .')',
            implode("','", TrackingView::values()),
        ));
    }

    public function down(): void
    {
        DB::table('report_sections')->where('kind', 'tracking')->delete();

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_tracking_view_check');
        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_has_metrics');
        DB::statement(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_has_metrics CHECK (\n"
            ."  kind IN ('store','lead','creative','traffic','attribution') OR jsonb_array_length(metrics) > 0\n"
            .')'
        );

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_kind_check');
        $earlier = array_values(array_filter(SectionKind::values(), static fn (string $v): bool => $v !== 'tracking'));
        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_kind_check CHECK (kind IN ('%s'))",
            implode("','", $earlier),
        ));
        DB::statement('ALTER TABLE report_sections DROP COLUMN tracking_view');
    }
};
