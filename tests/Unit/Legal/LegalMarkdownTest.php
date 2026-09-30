<?php

declare(strict_types=1);

use App\Modules\Legal\Services\LegalMarkdown;

/*
 * ADR-0056: legal texts are simple Markdown rendered to safe HTML: raw HTML
 * stripped, unsafe links dropped, web links in a new tab without opener or
 * referrer, images reduced to their text, headings below the page's own.
 */

function legalHtml(string $markdown, int $topLevel = 3): string
{
    return app(LegalMarkdown::class)->render($markdown, $topLevel)->toHtml();
}

it('strips raw HTML, blocks and inline', function (): void {
    $html = legalHtml("<script>alert(1)</script>\n\nHola <b onclick=\"x()\">mundo</b> <img src=x onerror=alert(1)>\n\n<iframe src=\"https://evil.example\"></iframe>");

    expect($html)->toContain('Hola mundo');

    foreach (['<script', '<b', 'onerror', '<iframe'] as $unsafe) {
        expect(str_contains($html, $unsafe))->toBeFalse($unsafe);
    }
});

it('drops unsafe links and opens web links in a new tab', function (): void {
    $html = legalHtml('[a](javascript:alert(1)) [b](vbscript:x) [c](data:text/html,x) [d](https://tienda.example/aviso) [e](mailto:soporte@tienda.example)');

    expect($html)
        ->toContain('<a rel="noopener noreferrer" target="_blank" href="https://tienda.example/aviso">d</a>')
        ->toContain('<a href="mailto:soporte@tienda.example">e</a>');

    foreach (['javascript:', 'vbscript:', 'data:text/html'] as $unsafe) {
        expect(str_contains($html, $unsafe))->toBeFalse($unsafe);
    }
});

it('shows images as their text alternative', function (): void {
    expect(legalHtml('Logo: ![Tienda Demo](https://evil.example/tracker.png)'))
        ->toBe("<p>Logo: Tienda Demo</p>\n");
});

it('starts headings at the given level and never goes past h6', function (): void {
    expect(legalHtml("# Uno\n\n## Dos", 3))->toContain('<h3>Uno</h3>')->toContain('<h4>Dos</h4>')
        ->and(legalHtml("# Uno\n\n###### Seis", 2))->toContain('<h2>Uno</h2>')->toContain('<h6>Seis</h6>');
});

it('renders lists, emphasis and quotes', function (): void {
    expect(legalHtml("- **uno**\n- *dos*\n\n> cita"))
        ->toContain('<li><strong>uno</strong></li>')
        ->toContain('<em>dos</em>')
        ->toContain('<blockquote>');
});

it('gives the same HTML from the cache', function (): void {
    $markdown = "# Aviso\n\nTexto.";

    expect(legalHtml($markdown))->toBe(legalHtml($markdown));
});
