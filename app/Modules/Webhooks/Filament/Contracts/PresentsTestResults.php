<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Filament\Contracts;

/**
 * A panel page that can open the test result dialog (ShowsTestResult).
 */
interface PresentsTestResults
{
    /**
     * @param  array{outcome: string, color: string, icon: string, next_step: ?string, rows: list<array{label: string, value: string, mono: bool}>, errors: list<string>, warnings: list<string>, excerpt: ?string}  $result
     */
    public function showTestResult(array $result, string $heading): void;
}
