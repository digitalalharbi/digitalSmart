<?php

declare(strict_types=1);

use App\Domain\Reporting\TrackingView;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A report's tracking section can show the client's GA4 website traffic. Only the allowed view names grow; no column changes.
 * Reversible: the sections using the new view are removed on the way down, because the narrower constraint would reject them.
 */
return new class extends Migration
{
    private const array ADDED = ['web_traffic'];

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
