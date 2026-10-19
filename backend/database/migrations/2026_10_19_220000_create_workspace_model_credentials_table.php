<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A workspace's own language-model key. Encrypted under the application key; only its last four characters are ever
 * shown, so an administrator can tell which key is in place without anyone being able to read it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_model_credentials', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->text('api_key');
            $table->string('last4', 4);
            $table->string('strong_model', 64)->nullable();
            $table->string('fast_model', 64)->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_model_credentials');
    }
};
