<?php

declare(strict_types=1);

use App\Domain\Tracking\Exception\InvalidForm;
use App\Domain\Tracking\Form\FieldVisibility;
use App\Domain\Tracking\Form\FormDefinition;
use App\Domain\Tracking\Form\FormValidator;

/** @return list<array<string, mixed>> */
function rawFields(): array
{
    return [
        ['key' => 'name', 'type' => 'text', 'label_ar' => 'الاسم', 'required' => true, 'maps_to' => 'name'],
        ['key' => 'phone', 'type' => 'phone', 'label_ar' => 'الجوال', 'required' => true, 'maps_to' => 'phone'],
        ['key' => 'kind', 'type' => 'select', 'label_en' => 'Kind', 'options' => [['value' => 'villa'], ['value' => 'land']], 'maps_to' => 'custom.property_type'],
        ['key' => 'land_size', 'type' => 'number', 'label_en' => 'Size', 'required' => true, 'visibility' => ['field' => 'kind', 'operator' => 'equals', 'value' => 'land'], 'validation' => ['min' => 100]],
        ['key' => 'consent', 'type' => 'consent', 'label_en' => 'I agree', 'required' => true],
    ];
}

it('validates what was submitted against the published fields only', function (): void {
    $fields = FormDefinition::fieldsFrom(rawFields());

    $ok = FormValidator::validate($fields, ['name' => ' Noura ', 'phone' => '٠٥٥٥٠٠١١٢٢', 'kind' => 'villa', 'consent' => true, 'land_size' => '5', 'extra' => 'smuggled']);

    expect($ok['errors'])->toBe([])
        ->and($ok['clean'])->toBe(['name' => 'Noura', 'phone' => '0555001122', 'kind' => 'villa', 'consent' => '1']);
});

it('requires a field only when it is shown, and checks it when it is', function (): void {
    $fields = FormDefinition::fieldsFrom(rawFields());

    $missing = FormValidator::validate($fields, ['name' => 'N', 'phone' => '0555001122', 'kind' => 'land', 'consent' => true]);
    $small = FormValidator::validate($fields, ['name' => 'N', 'phone' => '0555001122', 'kind' => 'land', 'land_size' => '10', 'consent' => true]);
    $refused = FormValidator::validate($fields, ['name' => '', 'phone' => '12', 'kind' => 'castle']);

    expect($missing['errors'])->toBe(['land_size' => 'required'])
        ->and($small['errors'])->toBe(['land_size' => 'too_small'])
        ->and($refused['errors'])->toBe(['name' => 'required', 'phone' => 'invalid', 'kind' => 'invalid', 'consent' => 'required']);
});

it('evaluates a visibility rule the same way for every operator, and hides a rule that points nowhere', function (): void {
    expect(FieldVisibility::isShown(null, []))->toBeTrue()
        ->and(FieldVisibility::isShown(['field' => 'a', 'operator' => 'equals', 'value' => 'x'], ['a' => 'x']))->toBeTrue()
        ->and(FieldVisibility::isShown(['field' => 'a', 'operator' => 'not_equals', 'value' => 'x'], ['a' => 'x']))->toBeFalse()
        ->and(FieldVisibility::isShown(['field' => 'a', 'operator' => 'in', 'value' => 'x, y'], ['a' => 'y']))->toBeTrue()
        ->and(FieldVisibility::isShown(['field' => 'a', 'operator' => 'filled', 'value' => null], ['a' => '']))->toBeFalse()
        ->and(FieldVisibility::isShown(['field' => 'a', 'operator' => 'bogus', 'value' => null], ['a' => 'x']))->toBeFalse();
});

it('refuses a form that could not be published safely', function (array $field, string $why): void {
    expect(fn () => FormDefinition::fieldsFrom([$field]))->toThrow(InvalidForm::class, $why);
})->with([
    'bad key' => [['key' => 'Bad Key', 'type' => 'text', 'label_en' => 'x'], 'lower-case'],
    'unknown type' => [['key' => 'a', 'type' => 'sql', 'label_en' => 'x'], 'unknown type'],
    'no label' => [['key' => 'a', 'type' => 'text'], 'label'],
    'select without options' => [['key' => 'a', 'type' => 'select', 'label_en' => 'x'], 'options'],
    'rule to itself' => [['key' => 'a', 'type' => 'text', 'label_en' => 'x', 'visibility' => ['field' => 'a', 'operator' => 'equals', 'value' => '1']], 'different field'],
    'rule to nowhere' => [['key' => 'a', 'type' => 'text', 'label_en' => 'x', 'visibility' => ['field' => 'zzz', 'operator' => 'equals', 'value' => '1']], 'different field'],
    'unknown mapping' => [['key' => 'a', 'type' => 'text', 'label_en' => 'x', 'maps_to' => 'owner_id'], 'does not exist'],
]);

it('refuses duplicate keys and a lead form that cannot reach anybody', function (): void {
    expect(fn () => FormDefinition::fieldsFrom([
        ['key' => 'a', 'type' => 'text', 'label_en' => 'x'], ['key' => 'a', 'type' => 'text', 'label_en' => 'y'],
    ]))->toThrow(InvalidForm::class, 'twice');

    $noContact = FormDefinition::fieldsFrom([['key' => 'name', 'type' => 'text', 'label_en' => 'x', 'maps_to' => 'name']]);
    expect(fn () => FormDefinition::assertCanCreateLeads($noContact))->toThrow(InvalidForm::class);
    FormDefinition::assertCanCreateLeads(FormDefinition::fieldsFrom(rawFields()));
});
