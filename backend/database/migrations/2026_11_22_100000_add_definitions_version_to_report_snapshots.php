<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A snapshot records which version of the metric definitions its figures were computed under, so a frozen figure
 * can be told apart from one the product would compute differently today. Nullable and never backfilled: a snapshot
 * taken before this column existed does not know its version, and writing today's onto it would be inventing one.
 * Reversible: the column is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_snapshots', function (Blueprint $table): void {
            $table->string('definitions_version', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_snapshots', function (Blueprint $table): void {
            $table->dropColumn('definitions_version');
        });
    }
};
