<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Platform user.
 *
 * This is the persistence/auth adapter for an identity. Roles, permissions,
 * workspace membership and profile behaviour arrive in Phase 1 — they belong to
 * the Domain layer, not to this Eloquent model.
 */
final class User extends Authenticatable
{
    use HasApiTokens;
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;
    use Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Whether this person is still using a password somebody else chose for them.
     *
     * Read from the loaded attributes rather than through the accessor, because this model class has strict
     * attribute access on: a model built in memory — a factory, a test — has not got the column, and asking for
     * it would throw rather than answer. The authenticated user always comes from a full row, so in a real
     * request the value is there.
     *
     * Absent reads as false, which is the right default for exactly one reason: the only models without the
     * column are ones nothing has signed in with.
     */
    public function mustChoosePassword(): bool
    {
        return (bool) ($this->getAttributes()['must_change_password'] ?? false);
    }

    /**
     * Factories cannot be guessed from the namespace once models live in the
     * persistence layer, so the binding is declared explicitly.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }
}
