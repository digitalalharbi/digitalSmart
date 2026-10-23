<?php

declare(strict_types=1);

use App\Domain\Reporting\TrackingView;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Five more things a client report can show about tracking. The CHECK is re-derived from the enum, never typed twice. */
return new class extends Migration
{
    private const array ADDED = ['roi', 'campaign_funnel', 'lead_quality', 'sales_funnel', 'response'];

    public function up(): void
    {
        $this->constrain(TrackingView::values());
    }

    public function down(): void
    {
        DB::table('report_sections')->whereIn('tracking_view', self::ADDED)->delete();
        $this->constrain(array_values(array_diff(TrackingView::values(), self::ADDED)));
    }

    /** @param list<string> $views */
    private function constrain(array $views): void
    {
        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_tracking_view_check');
        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_tracking_view_check CHECK (\n"
            ."  (kind = 'tracking') = (tracking_view IS NOT NULL)\n"
            ."  AND (tracking_view IS NULL OR tracking_view IN ('%s'))\n"
            .')',
            implode("','", $views),
        ));
    }
};
