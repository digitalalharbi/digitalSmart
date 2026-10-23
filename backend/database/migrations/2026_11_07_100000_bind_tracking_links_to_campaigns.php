<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A tracking link can name the campaign, ad group, ad and creative it advertises.
 *
 * The names a link always carried (`campaign`, `ad_group`, …) stay: they feed the UTM parameters and a link made before
 * its campaign was synced has nothing else. The new columns point at the campaign hierarchy the product already has, so
 * spend and outcomes are read from one place and never copied here. Deleting a campaign leaves the link standing and
 * unbound rather than deleting a link people have printed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_links', function (Blueprint $table): void {
            $table->foreignUuid('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignUuid('ad_group_id')->nullable()->constrained('ad_groups')->nullOnDelete();
            $table->foreignUuid('ad_id')->nullable()->constrained('ads')->nullOnDelete();
            $table->foreignUuid('creative_id')->nullable()->constrained('creatives')->nullOnDelete();
            $table->string('conversion_goal', 16)->nullable();

            $table->index('campaign_id');
            $table->index('ad_group_id');
            $table->index('ad_id');
        });

        /* The most specific level wins, and a level cannot be named without the one above it. */
        DB::statement(
            'ALTER TABLE tracking_links ADD CONSTRAINT tracking_links_binding_chain CHECK ('
            .'(ad_group_id IS NULL OR campaign_id IS NOT NULL) AND (ad_id IS NULL OR ad_group_id IS NOT NULL) '
            .'AND (creative_id IS NULL OR ad_id IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE tracking_links ADD CONSTRAINT tracking_links_goal_check CHECK ('
            ."conversion_goal IS NULL OR conversion_goal IN ('lead','qualified','appointment','sale'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tracking_links DROP CONSTRAINT tracking_links_goal_check');
        DB::statement('ALTER TABLE tracking_links DROP CONSTRAINT tracking_links_binding_chain');

        Schema::table('tracking_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('creative_id');
            $table->dropConstrainedForeignId('ad_id');
            $table->dropConstrainedForeignId('ad_group_id');
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropColumn('conversion_goal');
        });
    }
};
