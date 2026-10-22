<?php

declare(strict_types=1);

use App\Domain\Reporting\CreativeView;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Two more views of the creative picture: a media-first gallery and the platform mix. The CHECK follows the enum. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_creative_view_check');
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
        $newer = [CreativeView::Gallery->value, CreativeView::PlatformMix->value];
        DB::table('report_sections')->whereIn('creative_view', $newer)->delete();

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_creative_view_check');
        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_creative_view_check CHECK (\n"
            ."  (kind = 'creative') = (creative_view IS NOT NULL)\n"
            ."  AND (creative_view IS NULL OR creative_view IN ('%s'))\n"
            .')',
            implode("','", array_values(array_diff(CreativeView::values(), $newer))),
        ));
    }
};
