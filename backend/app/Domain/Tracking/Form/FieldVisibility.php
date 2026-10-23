<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Form;

/**
 * Whether a field is shown, given the answers so far.
 *
 * One rule shape — "show when <field> <operator> <value>" — evaluated the same way by the server and by the page, so the
 * page never offers a field the server would then refuse, and the server never requires one the visitor could not see.
 * A field whose condition names a field that does not exist is hidden: a broken rule must not demand an answer.
 */
final class FieldVisibility
{
    public const array OPERATORS = ['equals', 'not_equals', 'in', 'filled'];

    /**
     * @param  array{field: string, operator: string, value: string|null}|null  $rule
     * @param  array<string, mixed>  $answers
     */
    public static function isShown(?array $rule, array $answers): bool
    {
        if ($rule === null) {
            return true;
        }

        $actual = $answers[$rule['field']] ?? null;
        $actual = is_bool($actual) ? ($actual ? '1' : '0') : (is_scalar($actual) ? trim((string) $actual) : '');
        $expected = (string) ($rule['value'] ?? '');

        return match ($rule['operator']) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'in' => in_array($actual, array_map('trim', explode(',', $expected)), true),
            'filled' => $actual !== '' && $actual !== '0',
            default => false,
        };
    }
}
