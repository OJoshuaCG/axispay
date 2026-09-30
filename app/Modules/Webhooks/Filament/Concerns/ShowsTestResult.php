<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;

/**
 * The dialog with the result of "Send test event" or "Test validation"
 * (plan 15.1, 15.8.1). The result holds no secret: status, HTTP code,
 * latency, problems and the sanitized excerpt of the merchant's answer.
 */
trait ShowsTestResult
{
    /**
     * @var array{outcome: string, color: string, icon: string, next_step: ?string, rows: list<array{label: string, value: string, mono: bool}>, errors: list<string>, warnings: list<string>, excerpt: ?string}|null
     */
    #[Locked]
    public ?array $testResult = null;

    #[Locked]
    public ?string $testResultHeading = null;

    /**
     * Replaces the running action with the result dialog.
     *
     * @param  array{outcome: string, color: string, icon: string, next_step: ?string, rows: list<array{label: string, value: string, mono: bool}>, errors: list<string>, warnings: list<string>, excerpt: ?string}  $result
     */
    public function showTestResult(array $result, string $heading): void
    {
        $this->testResult = $result;
        $this->testResultHeading = $heading;
        $this->replaceMountedAction('showTestResult');
    }

    public function showTestResultAction(): Action
    {
        return Action::make('showTestResult')
            ->modalHeading(fn (): string => $this->testResultHeading ?? __('webhooks.test_result.heading'))
            ->modalIcon(Heroicon::OutlinedBeaker)
            ->modalWidth('2xl')
            ->schema([
                View::make('filament.webhooks.test-result')
                    ->viewData(fn (): array => ['result' => $this->testResult]),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('webhooks.test_result.close'));
    }
}
