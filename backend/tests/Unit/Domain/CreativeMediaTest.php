<?php

declare(strict_types=1);

use App\Domain\Advertising\CreativeMedia;

/*
|--------------------------------------------------------------------------
| What may be shown of a creative
|--------------------------------------------------------------------------
|
| A real image or nothing. The tests are the list of things that only look like a preview.
|
*/

it('accepts a path this product serves and an https address on the platform\'s own host', function (): void {
    $app = CreativeMedia::resolve('image', '/demo-creatives/hero.png');
    $provider = CreativeMedia::resolve('image', 'https://scontent.xx.fbcdn.net/v/t45/abc.jpg?stp=dst-jpg');

    expect($app->state)->toBe('available')->and($app->origin)->toBe('app')->and($app->kind)->toBe('image')
        ->and($provider->state)->toBe('available')->and($provider->origin)->toBe('provider');
});

it('marks a video\'s preview as a video\'s poster, and never plays it', function (): void {
    expect(CreativeMedia::resolve('video', 'https://cdn.example-provider.com/poster.jpg')->kind)->toBe('video');
});

it('has no preview when there is nothing, rather than a stand-in', function (): void {
    $none = CreativeMedia::resolve('image', null, '', '   ');

    expect($none->state)->toBe('none')->and($none->url)->toBeNull()->and($none->kind)->toBe('none')->and($none->origin)->toBeNull();
});

it('recognises a sandbox fixture address and refuses to draw it', function (string $url): void {
    $media = CreativeMedia::resolve('image', $url);

    expect($media->state)->toBe('fixture')->and($media->url)->toBeNull();
})->with([
    'https://sandbox.digitalsmart.invalid/meta/thumb/a.jpg',
    'https://cdn.client.test/a.png',
    'https://x.example/a.png',
    'https://localhost/a.png',
]);

it('refuses an address that carries a credential, an address that is not https, and one with a login in it', function (string $url): void {
    expect(CreativeMedia::resolve('image', $url)->state)->toBe('none');
})->with([
    'signed' => 'https://cdn.provider.com/a.jpg?signature=abc123',
    'token' => 'https://cdn.provider.com/a.jpg?access_token=abc',
    'key' => 'https://cdn.provider.com/a.jpg?Key=abc',
    'aws' => 'https://b.s3.amazonaws.com/a.jpg?X-Amz-Signature=abc',
    'plain http' => 'http://cdn.provider.com/a.jpg',
    'script' => 'javascript:alert(1)',
    'data' => 'data:image/png;base64,AAAA',
    'protocol relative' => '//evil.com/a.png',
    'credentials' => 'https://user:pass@cdn.provider.com/a.jpg',
    'garbage' => 'not a url',
]);

it('falls through to the next candidate when the first is not usable', function (): void {
    $media = CreativeMedia::resolve(
        'image',
        'https://cdn.provider.com/a.jpg?token=x',
        'https://cdn.provider.com/b.jpg',
    );

    expect($media->url)->toBe('https://cdn.provider.com/b.jpg');
});

it('says "fixture" only when nothing real was found behind it', function (): void {
    expect(CreativeMedia::resolve('image', 'https://s.invalid/a.jpg', 'https://cdn.provider.com/real.jpg')->state)->toBe('available')
        ->and(CreativeMedia::resolve('image', 'https://s.invalid/a.jpg', null)->state)->toBe('fixture');
});
