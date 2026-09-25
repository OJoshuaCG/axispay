<?php

declare(strict_types=1);

namespace App\Support\Filament\Concerns;

use Illuminate\Support\Str;

/**
 * Sentence case for resource labels in titles, buttons and navigation
 * (ADR-0044). Filament title-cases them by default ("Crear Cliente",
 * "Administradores De La Plataforma"), which Spanish style (RAE) does not
 * use and English UI copy in this product avoids too. With this trait the
 * singular label is used as translated ("Crear cliente") and the plural gets
 * only its first letter capitalized ("Clientes").
 */
trait SentenceCaseLabels
{
    public static function getTitleCaseModelLabel(): string
    {
        return static::getModelLabel();
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }
}
