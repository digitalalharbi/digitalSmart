<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let the four providers the registry already knows about actually be connected.
 *
 * Phase 6 added Google Analytics, Google Tag Manager, Salla and Zid to the provider registry
 * with adapters, credential keys, statuses and sandbox accounts — and the CHECK constraint
 * written in Phase 2 still listed only the six advertising platforms plus OpenAI. The result was
 * a provider that appeared on the integrations page, offered a Connect action, accepted
 * credentials through validation, and then failed at the insert with a constraint violation.
 *
 * Found by the first test that tried to connect GA4.
 *
 * The constraint is kept rather than dropped. It is the reason an unknown provider string cannot
 * reach a table that half the product reads, and "the application validates it" is exactly the
 * assurance that stopped being true the moment a second write path existed. The list is built
 * from the enum here, so the next provider added to the registry comes with a one-line migration
 * instead of a surprise at the insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->replaceWith(Provider::values());
    }

    public function down(): void
    {
        /*
         * The six advertising platforms and OpenAI, as Phase 2 wrote them. Reversing is only
         * safe if no row names one of the newer providers, so those rows go first — a down
         * migration that cannot complete is worse than one that is explicit about what it drops.
         */
        $original = ['meta', 'google_ads', 'tiktok', 'snapchat', 'x', 'linkedin', 'openai'];

        DB::table('provider_connections')->whereNotIn('provider', $original)->delete();

        $this->replaceWith($original);
    }

    /** @param list<string> $providers */
    private function replaceWith(array $providers): void
    {
        $quoted = implode(', ', array_map(
            static fn (string $provider): string => "'".str_replace("'", "''", $provider)."'",
            $providers,
        ));

        DB::statement('ALTER TABLE provider_connections DROP CONSTRAINT IF EXISTS provider_connections_provider_check');
        DB::statement(
            'ALTER TABLE provider_connections ADD CONSTRAINT provider_connections_provider_check '
            ."CHECK (provider IN ({$quoted}))"
        );
    }
};
