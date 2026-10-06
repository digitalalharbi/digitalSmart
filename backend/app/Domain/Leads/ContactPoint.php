<?php

declare(strict_types=1);

namespace App\Domain\Leads;

/**
 * A normalised way of reaching a person, used to recognise the same person twice.
 *
 * Normalising matters more here than it looks: the same customer arrives as `+966 55 500 1122` from a form,
 * `966555001122` from WhatsApp and `0555001122` from a phone log, and an agency that counts those as three
 * leads reports three times the volume at a third of the cost.
 *
 * What this will **not** do is match on a name. Two people called Mohammed Al-Harbi are two people, and
 * merging them would put one client's enquiry in another's record.
 */
final readonly class ContactPoint
{
    private function __construct(
        public ?string $email,
        public ?string $phone,
    ) {}

    public static function of(?string $email, ?string $phone, ?string $defaultCountryCode = '966'): self
    {
        return new self(
            email: self::normaliseEmail($email),
            phone: self::normalisePhone($phone, $defaultCountryCode),
        );
    }

    /** Whether there is any way to reach this person at all. */
    public function isReachable(): bool
    {
        return $this->email !== null || $this->phone !== null;
    }

    /**
     * A stable key for recognising the same person again, or null.
     *
     * The phone wins where both exist, because in this market it is the identifier people actually give and
     * the one they give consistently — an e-mail is often typed differently or skipped entirely.
     */
    public function matchKey(): ?string
    {
        return $this->phone ?? $this->email;
    }

    /**
     * Lower-cased and trimmed, and nothing more.
     *
     * Deliberately no Gmail dot-stripping or plus-tag removal: those are provider-specific behaviours, and
     * treating `a.b@gmail.com` and `ab@gmail.com` as one person is right for Gmail and wrong for a mail
     * server that distinguishes them. Merging two real people is worse than counting one twice.
     */
    private static function normaliseEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $trimmed = strtolower(trim($email));

        return $trimmed === '' || ! str_contains($trimmed, '@') ? null : $trimmed;
    }

    /**
     * Digits only, in international form where it can be worked out.
     *
     * A Saudi number arrives as `0555001122`, `555001122`, `+966555001122` and `00966555001122`, and all
     * four are the same phone. The leading zero is a national prefix rather than part of the number, which
     * is why dropping it is correct here and would be wrong for a country that keeps one.
     */
    private static function normalisePhone(?string $phone, ?string $defaultCountryCode): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        // `00` is the international access code; strip it before anything else.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($defaultCountryCode !== null && $defaultCountryCode !== '') {
            if (str_starts_with($digits, '0')) {
                // National form: drop the trunk prefix and apply the country code.
                $digits = $defaultCountryCode.substr($digits, 1);
            } elseif (! str_starts_with($digits, $defaultCountryCode) && strlen($digits) <= 9) {
                // A bare subscriber number, which only makes sense with the country assumed.
                $digits = $defaultCountryCode.$digits;
            }
        }

        /*
         * Too short to be a phone number anywhere. Returned as null rather than stored, because a
         * three-digit "phone" would match every other three-digit mistake and merge unrelated people.
         */
        return strlen($digits) < 8 ? null : $digits;
    }
}
