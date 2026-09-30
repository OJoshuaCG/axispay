<?php

declare(strict_types=1);

namespace App\Modules\Legal\Enums;

/**
 * How a legal document is published (ADR-0056): a text written in the panel
 * (simple Markdown, shown on the pay host) or a link to a page elsewhere.
 */
enum LegalDocumentFormat: string
{
    case Text = 'text';
    case Url = 'url';

    public function label(): string
    {
        return __('legal.format.'.$this->value);
    }

    /**
     * @return array<string, string> value => translated label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
