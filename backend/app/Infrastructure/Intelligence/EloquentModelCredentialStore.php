<?php

declare(strict_types=1);

namespace App\Infrastructure\Intelligence;

use App\Application\Intelligence\Contract\ModelCredentialStore;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class EloquentModelCredentialStore implements ModelCredentialStore
{
    public function credentials(string $workspaceId): ?array
    {
        $row = DB::table('workspace_model_credentials')->where('workspace_id', $workspaceId)->first();

        if ($row === null) {
            return null;
        }

        try {
            $key = Crypt::decryptString((string) $row->api_key);
        } catch (DecryptException) {
            /* A key encrypted under a rotated application key is no key: say so, do not guess. */
            return null;
        }

        return ['api_key' => $key, 'strong_model' => $row->strong_model, 'fast_model' => $row->fast_model];
    }

    public function describe(string $workspaceId): array
    {
        $row = DB::table('workspace_model_credentials')->where('workspace_id', $workspaceId)->first();

        return [
            'configured' => $row !== null,
            'last4' => $row === null ? null : (string) $row->last4,
            'strong_model' => $row?->strong_model,
            'fast_model' => $row?->fast_model,
            'updated_at' => $row === null ? null : (string) $row->updated_at,
        ];
    }

    public function save(string $workspaceId, ?string $apiKey, ?string $strongModel, ?string $fastModel, string $userId): void
    {
        $existing = DB::table('workspace_model_credentials')->where('workspace_id', $workspaceId)->exists();
        $values = ['strong_model' => $strongModel, 'fast_model' => $fastModel, 'updated_by' => $userId, 'updated_at' => now()];

        if ($apiKey !== null) {
            $values['api_key'] = Crypt::encryptString($apiKey);
            $values['last4'] = mb_substr($apiKey, -4);
        }

        if ($existing) {
            DB::table('workspace_model_credentials')->where('workspace_id', $workspaceId)->update($values);

            return;
        }

        if ($apiKey === null) {
            return;
        }

        DB::table('workspace_model_credentials')->insert([...$values, 'workspace_id' => $workspaceId, 'provider' => 'anthropic', 'created_at' => now()]);
    }

    public function forget(string $workspaceId): void
    {
        DB::table('workspace_model_credentials')->where('workspace_id', $workspaceId)->delete();
    }
}
