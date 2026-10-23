<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Form;

use DateTimeImmutable;

/**
 * Checks a submission against the form that was published.
 *
 * **The server is the only judge.** The page validates for the visitor's convenience; this decides. It keeps an answer only
 * for a field the form defines and that was *visible* given the other answers, so a hidden field cannot be used to smuggle
 * a value in, and a field the visitor could not see is never required of them.
 */
final class FormValidator
{
    private const int TEXT_MAX = 500;

    private const int AREA_MAX = 4000;

    /**
     * @param  list<FormField>  $fields
     * @param  array<string, mixed>  $values
     * @return array{clean: array<string, string>, errors: array<string, string>}
     */
    public static function validate(array $fields, array $values): array
    {
        usort($fields, static fn (FormField $a, FormField $b): int => $a->position <=> $b->position);

        $clean = [];
        $errors = [];

        foreach ($fields as $field) {
            if (! FieldVisibility::isShown($field->visibility, $clean)) {
                continue;
            }

            $raw = $values[$field->key] ?? null;
            $given = is_bool($raw) ? ($raw ? '1' : '') : (is_scalar($raw) ? trim((string) $raw) : '');

            if ($field->type === FormFieldType::Hidden) {
                if ($given !== '') {
                    $clean[$field->key] = mb_substr($given, 0, 190);
                } elseif ($field->default !== null) {
                    $clean[$field->key] = $field->default;
                }

                continue;
            }

            if ($given === '') {
                if ($field->required) {
                    $errors[$field->key] = 'required';
                }

                continue;
            }

            $error = self::check($field, $given);

            if ($error !== null) {
                $errors[$field->key] = $error;

                continue;
            }

            $clean[$field->key] = $field->type === FormFieldType::Phone ? self::phone($given) : $given;
        }

        return ['clean' => $clean, 'errors' => $errors];
    }

    private static function check(FormField $field, string $value): ?string
    {
        $v = $field->validation;
        $length = mb_strlen($value);
        $max = isset($v['max_length']) ? (int) $v['max_length'] : ($field->type === FormFieldType::Textarea ? self::AREA_MAX : self::TEXT_MAX);

        if ($length > $max) {
            return 'too_long';
        }

        if (isset($v['min_length']) && $length < (int) $v['min_length']) {
            return 'too_short';
        }

        return match ($field->type) {
            FormFieldType::Email => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? 'invalid' : null,
            FormFieldType::Phone => preg_match('/^\d{7,15}$/', self::phone($value)) === 1 ? null : 'invalid',
            FormFieldType::Number => self::number($value, $v),
            FormFieldType::Date => self::date($value),
            FormFieldType::Select, FormFieldType::Radio => in_array($value, array_column($field->options, 'value'), true) ? null : 'invalid',
            FormFieldType::Checkbox, FormFieldType::Consent => $value === '1' ? null : 'invalid',
            default => null,
        };
    }

    /** @param array<string, int|float|string> $v */
    private static function number(string $value, array $v): ?string
    {
        if (! is_numeric($value)) {
            return 'invalid';
        }

        if (isset($v['min']) && (float) $value < (float) $v['min']) {
            return 'too_small';
        }

        return isset($v['max']) && (float) $value > (float) $v['max'] ? 'too_large' : null;
    }

    private static function date(string $value): ?string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value ? null : 'invalid';
    }

    /** Digits only, with Arabic-Indic numerals read as the digits they are. */
    private static function phone(string $value): string
    {
        $latin = strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return (string) preg_replace('/\D+/', '', $latin);
    }
}
