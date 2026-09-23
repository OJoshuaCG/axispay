<?php

declare(strict_types=1);

use App\Modules\Tenancy\Enums\TenantStatus;

it('exposes the behavior hooks of plan 21.3', function (TenantStatus $status, bool $createsLinks, bool $panel, bool $readOnly): void {
    expect($status->allowsLinkCreation())->toBe($createsLinks)
        ->and($status->allowsPanelAccess())->toBe($panel)
        ->and($status->isPanelReadOnly())->toBe($readOnly);
})->with([
    'pending_onboarding' => [TenantStatus::PendingOnboarding, false, true, false],
    'active' => [TenantStatus::Active, true, true, false],
    'grace' => [TenantStatus::Grace, true, true, false],
    'suspended' => [TenantStatus::Suspended, false, true, true],
    'closed' => [TenantStatus::Closed, false, false, true],
]);

it('treats closed as terminal', function (): void {
    expect(TenantStatus::Closed->allowedTransitions())->toBe([])
        ->and(TenantStatus::Active->canTransitionTo(TenantStatus::PendingOnboarding))->toBeFalse()
        ->and(TenantStatus::Suspended->canTransitionTo(TenantStatus::Active))->toBeTrue();
});
