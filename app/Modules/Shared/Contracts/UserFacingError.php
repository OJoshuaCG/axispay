<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * A domain refusal that carries its own translated message for people using
 * the panels (as opposed to API errors, translated by their code).
 */
interface UserFacingError
{
    public function userMessage(): string;
}
