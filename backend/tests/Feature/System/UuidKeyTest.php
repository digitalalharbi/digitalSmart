<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Eloquent\Model\User;

it('gives API-facing entities a uuid primary key', function (): void {
    $user = User::factory()->create();

    expect($user->getKey())->toBeUuid()
        ->and($user->getKeyName())->toBe('id')
        ->and($user->getIncrementing())->toBeFalse();
});

it('issues time-ordered uuids so index inserts stay sequential', function (): void {
    $first = User::factory()->create()->getKey();
    $second = User::factory()->create()->getKey();

    // UUIDv7 encodes a millisecond timestamp in its leading bits.
    expect(strcmp((string) $first, (string) $second))->toBeLessThanOrEqual(0);
});

it('never exposes the password hash when serialising a user', function (): void {
    $user = User::factory()->create();

    expect($user->toArray())->not->toHaveKey('password')
        ->and($user->toArray())->not->toHaveKey('remember_token');
});
