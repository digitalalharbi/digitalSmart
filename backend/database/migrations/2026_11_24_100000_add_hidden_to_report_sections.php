<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A section can be kept in a report and shown nowhere: not in the live view, a shared link, a snapshot or an export.
 * Every existing section stays visible, so nothing already saved or shared changes what it shows. Reversible: the
 * column is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_sections', function (Blueprint $table): void {
            $table->boolean('hidden')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('report_sections', function (Blueprint $table): void {
            $table->dropColumn('hidden');
        });
    }
};
