<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use App\Domain\Notifications\NotificationSubject;

/*
|--------------------------------------------------------------------------
| Severity is a rule
|--------------------------------------------------------------------------
|
| Red means "half worse", amber means "a fifth worse", green means "a fifth better" — the same lines the campaign
| health policy reads a cost rise by. Nothing here is chosen by hand, and every kind files itself under one tab
| and one subject so the centre's tabs are a WHERE clause rather than a list somebody keeps.
|
*/

it('reads a figure that moved against the reader by two fixed lines, and a figure that improved only past the first', function (): void {
    expect(NotificationSeverity::forAdverseChange(0.5))->toBe(NotificationSeverity::Critical)
        ->and(NotificationSeverity::forAdverseChange(0.49))->toBe(NotificationSeverity::Attention)
        ->and(NotificationSeverity::forAdverseChange(0.2))->toBe(NotificationSeverity::Attention)
        ->and(NotificationSeverity::forAdverseChange(0.19))->toBe(NotificationSeverity::Info)
        ->and(NotificationSeverity::forAdverseChange(-0.19))->toBe(NotificationSeverity::Info)
        ->and(NotificationSeverity::forAdverseChange(-0.2))->toBe(NotificationSeverity::Success)
        ->and(NotificationSeverity::forAdverseChange(-0.9))->toBe(NotificationSeverity::Success);
});

it('ranks critical above attention above success above info', function (): void {
    $ranks = array_map(static fn (NotificationSeverity $s): int => $s->rank(), [
        NotificationSeverity::Info, NotificationSeverity::Success, NotificationSeverity::Attention, NotificationSeverity::Critical,
    ]);

    expect($ranks)->toBe([0, 1, 2, 3]);
});

it('files every kind under one tab and one subject, with a permission a recipient must hold', function (): void {
    foreach (NotificationKind::cases() as $kind) {
        expect($kind->category())->toBeInstanceOf(NotificationCategory::class)
            ->and($kind->subject())->toBeInstanceOf(NotificationSubject::class)
            ->and($kind->requires()->value)->not->toBe('')
            ->and($kind->label())->not->toBe('');
    }
});

it('asks for a decision on every alert but the one that merely ended, and on no digest', function (): void {
    expect(NotificationKind::CampaignEnded->needsAction())->toBeFalse()
        ->and(NotificationKind::PerformanceDigest->needsAction())->toBeFalse()
        ->and(NotificationKind::RoasDrop->needsAction())->toBeTrue()
        ->and(NotificationKind::BudgetNearLimit->needsAction())->toBeTrue()
        ->and(NotificationKind::CreativeFatigue->needsAction())->toBeTrue();
});
