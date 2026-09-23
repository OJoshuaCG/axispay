<?php

declare(strict_types=1);

/**
 * docs/frontend/i18n.md: lang/en and lang/es must have identical key sets.
 */

/**
 * @param  array<mixed>  $array
 * @return list<string>
 */
function flattenLangKeys(array $array, string $prefix = ''): array
{
    $keys = [];

    foreach ($array as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        $keys = [...$keys, ...(is_array($value) ? flattenLangKeys($value, $path) : [$path])];
    }

    return $keys;
}

it('keeps English and Spanish translation keys in sync', function (string $file): void {
    $en = require dirname(__DIR__, 2)."/lang/en/{$file}";
    $es = require dirname(__DIR__, 2)."/lang/es/{$file}";

    expect(is_array($en) && is_array($es))->toBeTrue();
    expect(flattenLangKeys((array) $es))->toEqualCanonicalizing(flattenLangKeys((array) $en));
})->with(array_map('basename', glob(dirname(__DIR__, 2).'/lang/en/*.php') ?: []));
