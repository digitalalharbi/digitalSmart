<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Form;

use App\Domain\Tracking\Exception\InvalidForm;

/**
 * Turns what a builder sent into fields that are safe to publish, or says what is wrong with them.
 *
 * Strict on purpose: a form is public, so a key that does not match a pattern, a choice with no options, a rule that
 * points at a field that does not exist or a mapping to a lead field that does not exist is refused when the form is
 * saved, not discovered by the first visitor.
 */
final class FormDefinition
{
    public const int MAX_FIELDS = 40;

    private const array CORE_TARGETS = ['name', 'email', 'phone', 'message'];

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<FormField>
     */
    public static function fieldsFrom(array $raw): array
    {
        if (count($raw) > self::MAX_FIELDS) {
            throw new InvalidForm(sprintf('A form can have at most %d fields.', self::MAX_FIELDS));
        }

        $keys = array_map(static fn (array $f): string => (string) ($f['key'] ?? ''), $raw);
        $fields = [];
        $seen = [];

        foreach (array_values($raw) as $i => $f) {
            $key = (string) ($f['key'] ?? '');

            if (preg_match('/^[a-z][a-z0-9_]{0,38}$/', $key) !== 1) {
                throw new InvalidForm('A field key uses lower-case letters, numbers and underscores, and starts with a letter.');
            }

            if (isset($seen[$key])) {
                throw new InvalidForm(sprintf('The field key "%s" is used twice.', $key));
            }

            $seen[$key] = true;
            $type = FormFieldType::tryFrom((string) ($f['type'] ?? '')) ?? throw new InvalidForm(sprintf('"%s" has an unknown type.', $key));

            if (! self::text($f['label_ar'] ?? null) && ! self::text($f['label_en'] ?? null) && $type !== FormFieldType::Hidden) {
                throw new InvalidForm(sprintf('"%s" needs a label in at least one language.', $key));
            }

            $options = self::options($key, $type, $f['options'] ?? []);
            $visibility = self::visibility($key, $f['visibility'] ?? null, $keys);
            $mapsTo = self::mapping($key, $f['maps_to'] ?? null);

            $fields[] = new FormField(
                $key, $type, $i,
                self::clip($f['label_ar'] ?? null, 190), self::clip($f['label_en'] ?? null, 190),
                self::clip($f['placeholder_ar'] ?? null, 190), self::clip($f['placeholder_en'] ?? null, 190),
                self::clip($f['help_ar'] ?? null, 300), self::clip($f['help_en'] ?? null, 300),
                (bool) ($f['required'] ?? false) && $type !== FormFieldType::Hidden,
                self::clip($f['default'] ?? null, 190),
                $options,
                self::validation($f['validation'] ?? []),
                $visibility,
                $mapsTo,
            );
        }

        return $fields;
    }

    /**
     * A lead needs somebody to call: a name, and a phone or an email, each mapped by a field that is not hidden by a rule.
     *
     * @param  list<FormField>  $fields
     */
    public static function assertCanCreateLeads(array $fields): void
    {
        $mapped = array_filter(array_map(static fn (FormField $f): ?string => $f->mapsTo, $fields));

        if (! in_array('name', $mapped, true) || (! in_array('phone', $mapped, true) && ! in_array('email', $mapped, true))) {
            throw new InvalidForm('To create leads, map a field to the lead’s name and a field to its phone or email.');
        }
    }

    private static function text(mixed $v): bool
    {
        return is_string($v) && trim($v) !== '';
    }

    private static function clip(mixed $v, int $max): ?string
    {
        return is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
    }

    /** @return list<array{value: string, label_ar: string|null, label_en: string|null}> */
    private static function options(string $key, FormFieldType $type, mixed $raw): array
    {
        if (! $type->hasOptions()) {
            return [];
        }

        if (! is_array($raw) || $raw === [] || count($raw) > 50) {
            throw new InvalidForm(sprintf('"%s" needs between 1 and 50 options.', $key));
        }

        $out = [];
        $values = [];

        foreach ($raw as $o) {
            $value = is_array($o) ? trim((string) ($o['value'] ?? '')) : '';

            if ($value === '' || mb_strlen($value) > 120 || isset($values[$value])) {
                throw new InvalidForm(sprintf('"%s" has an empty, long or repeated option value.', $key));
            }

            $values[$value] = true;
            $out[] = ['value' => $value, 'label_ar' => self::clip($o['label_ar'] ?? null, 190), 'label_en' => self::clip($o['label_en'] ?? null, 190)];
        }

        return $out;
    }

    /**
     * @param  list<string>  $keys
     * @return array{field: string, operator: string, value: string|null}|null
     */
    private static function visibility(string $key, mixed $raw, array $keys): ?array
    {
        if (! is_array($raw) || ! isset($raw['field'])) {
            return null;
        }

        $field = (string) $raw['field'];
        $operator = (string) ($raw['operator'] ?? 'equals');

        if ($field === $key || ! in_array($field, $keys, true)) {
            throw new InvalidForm(sprintf('The rule on "%s" must point at a different field of this form.', $key));
        }

        if (! in_array($operator, FieldVisibility::OPERATORS, true)) {
            throw new InvalidForm(sprintf('The rule on "%s" uses an unknown condition.', $key));
        }

        return ['field' => $field, 'operator' => $operator, 'value' => isset($raw['value']) ? mb_substr((string) $raw['value'], 0, 190) : null];
    }

    private static function mapping(string $key, mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        if (in_array($raw, self::CORE_TARGETS, true) || preg_match('/^custom\.[a-z][a-z0-9_]{0,38}$/', $raw) === 1) {
            return $raw;
        }

        throw new InvalidForm(sprintf('"%s" maps to a lead field that does not exist.', $key));
    }

    /** @return array<string, int|float|string> */
    private static function validation(mixed $raw): array
    {
        $out = [];

        foreach (['min_length', 'max_length', 'min', 'max'] as $k) {
            if (is_array($raw) && isset($raw[$k]) && is_numeric($raw[$k])) {
                $out[$k] = $raw[$k] + 0;
            }
        }

        return $out;
    }
}
