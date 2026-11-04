<?php

declare(strict_types=1);

use App\Application\Notifications\Mail\EmailKind;
use App\Infrastructure\Notifications\Mail\BrandedEmailRenderer;

/*
|--------------------------------------------------------------------------
| The messages this product writes
|--------------------------------------------------------------------------
|
| Nothing here sends anything: there is no mail provider, and delivery stays `awaiting_credentials`. These tests hold what
| a message may contain — the product's name first, a direction that matches the language, an escaped everything, and one
| https link — so the day a provider exists the content is already safe.
|
*/

function mailer(bool $allowInsecure = false): BrandedEmailRenderer
{
    return new BrandedEmailRenderer(brand: 'digitalSmart', allowInsecureLinks: $allowInsecure);
}

it('starts every subject with the product name, in both languages and for every kind', function (): void {
    foreach (EmailKind::cases() as $kind) {
        foreach (['en', 'ar'] as $locale) {
            $mail = mailer()->render($kind, $locale, 'https://app.example.com/x', ['subject' => 'X']);

            expect($mail->subject)->toStartWith('digitalSmart — ')
                ->and(mb_strlen($mail->subject))->toBeGreaterThan(18)
                ->and($mail->html)->toContain('<title>'.htmlspecialchars($mail->subject, ENT_QUOTES, 'UTF-8').'</title>')
                ->and($mail->text)->toContain('https://app.example.com/x');
        }
    }
});

it('lays Arabic out right to left and English left to right, stating alignment explicitly', function (): void {
    $arabic = mailer()->render(EmailKind::WeeklyReport, 'ar', 'https://app.example.com/r', ['subject' => 'تقرير']);
    $english = mailer()->render(EmailKind::WeeklyReport, 'en', 'https://app.example.com/r', ['subject' => 'Report']);

    expect($arabic->html)->toContain('lang="ar" dir="rtl"')->toContain('text-align:right')->toContain('direction:rtl')
        ->and($arabic->subject)->toBe('digitalSmart — تقريرك الأسبوعي')
        ->and($english->html)->toContain('lang="en" dir="ltr"')->toContain('text-align:left')
        ->and($english->subject)->toBe('digitalSmart — Your weekly report')
        ->and($english->html)->toContain('max-width:600px')->toContain('viewport');
});

it('escapes everything the sender controls, in the HTML and in the plain text', function (): void {
    $mail = mailer()->render(EmailKind::Invitation, 'en', 'https://app.example.com/i', [
        'name' => '<script>alert(1)</script>',
        'subject' => 'Acme "Ads" & <b>Co</b>',
        'detail' => '<img src=x onerror=alert(1)>',
    ]);

    foreach (['<script>', '<img', '<b>Co</b>'] as $raw) {
        expect($mail->html)->not->toContain($raw);
    }

    expect($mail->html)->toContain('Acme &quot;Ads&quot; &amp; &lt;b&gt;Co&lt;/b&gt;');

    // A header-injection attempt in the thing the subject is about cannot add a line to the subject or the text's first line.
    $injected = mailer()->render(EmailKind::NeedsAttention, 'en', 'https://app.example.com/a', ['subject' => "Hi\r\nBcc: x@evil.test"]);
    expect($injected->subject)->not->toContain("\n");
    expect($injected->subject)->not->toContain("\r");
});

it('accepts only an https link, or http on a developer machine', function (): void {
    foreach (['javascript:alert(1)', 'http://app.example.com/', '//evil.example/x', 'https:///nohost', 'https://a.example/x y', 'ftp://a.example/', ''] as $bad) {
        expect(fn () => mailer()->render(EmailKind::NeedsAttention, 'en', $bad))->toThrow(InvalidArgumentException::class);
    }

    expect(mailer(allowInsecure: true)->render(EmailKind::NeedsAttention, 'en', 'http://127.0.0.1:5174/x')->text)->toContain('http://127.0.0.1:5174/x');
    expect(fn () => mailer(allowInsecure: true)->render(EmailKind::NeedsAttention, 'en', 'javascript:alert(1)'))->toThrow(InvalidArgumentException::class);
});

it('tells a stranger what the message is and that nothing is asked of them', function (): void {
    $english = mailer()->render(EmailKind::SecurityNotice, 'en', 'https://app.example.com/s', ['subject' => 'A new sign-in.']);
    $arabic = mailer()->render(EmailKind::SecurityNotice, 'ar', 'https://app.example.com/s', ['subject' => 'تسجيل دخول جديد.']);

    expect($english->text)->toContain('ignore it')->and($arabic->text)->toContain('فتجاهلها');
});

it('does not use the names of the other products', function (): void {
    foreach (EmailKind::cases() as $kind) {
        foreach (['en', 'ar'] as $locale) {
            $mail = mailer()->render($kind, $locale, 'https://app.example.com/x', ['subject' => 'X']);

            foreach (['campaignshub', 'mohtam', 'مهتم'] as $other) {
                expect(mb_strtolower($mail->subject.$mail->html.$mail->text))->not->toContain($other);
            }
        }
    }
});
