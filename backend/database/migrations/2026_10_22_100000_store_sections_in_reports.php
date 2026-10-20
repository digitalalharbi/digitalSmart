<?php

declare(strict_types=1);

use App\Domain\Commerce\RevenueSource;
use App\Domain\Reporting\SectionKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A report can say what a client's stores sold, according to one named authority.
 *
 * The authority is a column on the section rather than a metric, because a store's revenue is not an ad figure: it
 * comes from orders, and "revenue" from a store beside "revenue" from a platform is two claims under one word. Two
 * rules live in the schema so no second write path can break them:
 *
 *  - a store section **always** names its source, and no other kind may carry one — a column that means something
 *    only for one kind is how a stray value ends up changing a different section's figures;
 *  - the source is one the product knows, generated from the enum like every other vocabulary here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_sections', function (Blueprint $table): void {
            $table->string('revenue_source')->nullable()->after('dimension');
        });

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_kind_check');
        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_kind_check CHECK (kind IN ('%s'))",
            implode("','", SectionKind::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_revenue_source_check CHECK (\n"
            ."  revenue_source IS NULL OR revenue_source IN ('%s')\n"
            .')',
            implode("','", array_map(static fn (RevenueSource $source): string => $source->value, RevenueSource::cases())),
        ));

        /* A store section carries a source instead of metrics, so "at least one metric" now applies to the rest. */
        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_has_metrics');
        DB::statement(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_has_metrics CHECK (\n"
            ."  kind = 'store' OR jsonb_array_length(metrics) > 0\n"
            .')'
        );

        DB::statement(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_store_names_source CHECK (\n"
            ."  (kind = 'store') = (revenue_source IS NOT NULL)\n"
            .')'
        );
    }

    public function down(): void
    {
        DB::table('report_sections')->where('kind', SectionKind::Store->value)->delete();

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_has_metrics');
        DB::statement(
            'ALTER TABLE report_sections ADD CONSTRAINT report_sections_has_metrics CHECK ('."\n"
            ."  jsonb_array_length(metrics) > 0\n"
            .')'
        );

        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_store_names_source');
        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_revenue_source_check');
        DB::statement('ALTER TABLE report_sections DROP CONSTRAINT report_sections_kind_check');

        $earlier = array_values(array_filter(
            SectionKind::values(),
            static fn (string $kind): bool => $kind !== SectionKind::Store->value,
        ));

        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_kind_check CHECK (kind IN ('%s'))",
            implode("','", $earlier),
        ));

        Schema::table('report_sections', function (Blueprint $table): void {
            $table->dropColumn('revenue_source');
        });
    }
};
