<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Application\Platform\Contract\PlatformOperators;
use App\Domain\Identity\Email;
use App\Domain\Identity\UserId;
use Illuminate\Support\Facades\DB;

final readonly class EloquentPlatformOperators implements PlatformOperators
{
    public function isOperator(UserId $userId): bool
    {
        return DB::table('users')->where('id', $userId->value)->where('is_platform_operator', true)->exists();
    }

    public function set(Email $email, bool $operator): ?string
    {
        $id = DB::table('users')->whereRaw('lower(email) = ?', [strtolower($email->value)])->value('id');

        if ($id === null) {
            return null;
        }

        DB::table('users')->where('id', $id)->update(['is_platform_operator' => $operator, 'updated_at' => now()]);

        return (string) $id;
    }
}
