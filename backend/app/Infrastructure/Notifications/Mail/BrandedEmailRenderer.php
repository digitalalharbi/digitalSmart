<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Mail;

use App\Application\Notifications\Mail\EmailKind;
use App\Application\Notifications\Mail\EmailRenderer;
use App\Application\Notifications\Mail\RenderedEmail;
use InvalidArgumentException;

/**
 * One responsive, table-based layout for every message, in the product's own name.
 *
 * Built for the mail clients that ignore modern CSS: tables, inline styles, a 600-pixel column that narrows on a phone,
 * `dir` and `lang` set from the locale and alignment stated explicitly (Arabic is laid out right to left, not mirrored by
 * accident). Every interpolated value is escaped; the one link must be https (http only when the application runs
 * locally), so a message never carries `javascript:` or a bare local address into someone's inbox.
 *
 * The subject always starts with the product's name, so a recipient can tell whose message it is before opening it.
 */
final readonly class BrandedEmailRenderer implements EmailRenderer
{
    public function __construct(
        private string $brand = 'digitalSmart',
        private string $accent = '#C24A2C',
        private bool $allowInsecureLinks = false,
    ) {}

    public function render(EmailKind $kind, string $locale, string $url, array $parts = []): RenderedEmail
    {
        $locale = $locale === 'ar' ? 'ar' : 'en';
        $this->assertSafeLink($url);

        $copy = EmailCatalogue::for($kind, $locale);
        $subject = $this->fill($copy['subject'], $parts, false);
        $rtl = $locale === 'ar';

        $greeting = EmailCatalogue::greeting($locale, $parts['name'] ?? null);
        $htmlLines = array_map(fn (string $line): string => $this->fill($line, $parts, true), $copy['lines']);
        $textLines = array_map(fn (string $line): string => $this->fill($line, $parts, false), $copy['lines']);

        if (($parts['detail'] ?? null) !== null && trim((string) $parts['detail']) !== '') {
            $htmlLines[] = $this->escape((string) $parts['detail']);
            $textLines[] = trim((string) $parts['detail']);
        }

        $dir = $rtl ? 'rtl' : 'ltr';
        $align = $rtl ? 'right' : 'left';
        $font = $rtl
            ? "'IBM Plex Sans Arabic',Tahoma,Arial,sans-serif"
            : "Sora,'Helvetica Neue',Helvetica,Arial,sans-serif";

        $body = '';

        if ($greeting !== null) {
            $body .= $this->paragraph($this->fill($greeting, $parts, true), $align);
        }

        foreach ($htmlLines as $line) {
            $body .= $this->paragraph($line, $align);
        }

        $heading = $this->fill($copy['heading'], $parts, true);
        $href = $this->escape($url);
        $cta = $this->escape($copy['cta']);
        $footer = $this->escape(EmailCatalogue::footer($locale));
        $brand = $this->escape($this->brand);
        $accent = $this->escape($this->accent);

        $html = <<<HTML
<!doctype html>
<html lang="{$locale}" dir="{$dir}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<title>{$this->escape($this->prefixed($subject))}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f2;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f2;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border-radius:16px;font-family:{$font};direction:{$dir};">
<tr><td style="padding:24px 28px 0 28px;text-align:{$align};font-size:15px;font-weight:700;color:{$accent};">{$brand}</td></tr>
<tr><td style="padding:16px 28px 0 28px;text-align:{$align};font-size:22px;line-height:1.35;font-weight:700;color:#1b1b1b;">{$heading}</td></tr>
<tr><td style="padding:12px 28px 0 28px;text-align:{$align};">{$body}</td></tr>
<tr><td style="padding:20px 28px 8px 28px;text-align:{$align};"><a href="{$href}" style="display:inline-block;background:{$accent};color:#ffffff;text-decoration:none;font-size:16px;font-weight:700;line-height:1.2;padding:14px 26px;border-radius:12px;">{$cta}</a></td></tr>
<tr><td style="padding:12px 28px 0 28px;text-align:{$align};font-size:12px;line-height:1.6;color:#6b6b6b;word-break:break-all;">{$href}</td></tr>
<tr><td style="padding:20px 28px 24px 28px;text-align:{$align};font-size:12px;line-height:1.6;color:#6b6b6b;">{$footer}</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;

        $text = implode("\n\n", array_filter([
            $this->prefixed($subject),
            $greeting === null ? null : $this->fill($greeting, $parts, false),
            ...$textLines,
            $copy['cta'].': '.$url,
            EmailCatalogue::footer($locale),
        ], static fn (?string $part): bool => $part !== null && $part !== ''))."\n";

        return new RenderedEmail($this->prefixed($subject), $html, $text);
    }

    private function prefixed(string $subject): string
    {
        return $this->brand.' — '.$subject;
    }

    private function paragraph(string $html, string $align): string
    {
        return '<p style="margin:0 0 12px 0;text-align:'.$align.';font-size:16px;line-height:1.6;color:#2b2b2b;">'.$html.'</p>';
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function fill(string $template, array $parts, bool $html): string
    {
        $value = fn (string $key): string => trim((string) ($parts[$key] ?? ''));
        $clean = fn (string $text): string => $html ? $this->escape($text) : $this->plain($text);

        return strtr($template, [
            '{subject}' => $clean($value('subject')),
            '{name}' => $clean($value('name')),
        ]);
    }

    /** Plain text must not smuggle a header break or a control character either. */
    private function plain(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function assertSafeLink(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $allowed = $scheme === 'https' || ($this->allowInsecureLinks && $scheme === 'http');

        if (! $allowed || ! is_string($host) || $host === '' || strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('An email link must be an https address.');
        }
    }
}
