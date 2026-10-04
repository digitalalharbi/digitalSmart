<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 5)->default('en')->after('email');
            $table->string('timezone')->default('UTC')->after('locale');
            $table->string('job_title')->nullable()->after('timezone');
            $table->string('avatar_path')->nullable()->after('job_title');
            // Deactivated users keep their audit trail but cannot authenticate.
            $table->boolean('is_active')->default(true)->after('avatar_path');
            $table->timestampTz('last_login_at')->nullable()->after('is_active');
            $table->softDeletesTz();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'locale', 'timezone', 'job_title', 'avatar_path',
                'is_active', 'last_login_at', 'deleted_at',
            ]);
        });
    }
};
