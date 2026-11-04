<?php

use Symfony\Component\Finder\Finder;

/*
 * itp-server reaches into itp-core only through its models, enums and service provider.
 * Anything else (schema helpers, model concerns, factories) is itp-core's own business, so a
 * change there can't break the server unnoticed.
 */
it('uses only itp-core models, enums and providers', function () {
    $allowed = ['Itp\Core\Models\\', 'Itp\Core\Enums\\', 'Itp\Core\ItpCoreServiceProvider'];
    $internal = ['Itp\Core\Models\Concerns\\'];

    $root = dirname(__DIR__, 2);
    $files = Finder::create()->files()->name('*.php')->in(
        array_map(fn (string $directory) => "{$root}/{$directory}", ['app', 'bootstrap', 'config', 'database', 'routes']),
    )->exclude('cache');

    $references = [];

    foreach ($files as $file) {
        foreach (PhpToken::tokenize($file->getContents()) as $token) {
            if (! $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            $name = ltrim($token->text, '\\');

            if (! str_starts_with($name, 'Itp\Core\\')) {
                continue;
            }

            $isAllowed = collect($allowed)->contains(fn (string $prefix) => str_starts_with($name, $prefix))
                && ! collect($internal)->contains(fn (string $prefix) => str_starts_with($name, $prefix));

            if (! $isAllowed) {
                $references[] = $file->getRelativePathname().': '.$name;
            }
        }
    }

    expect($references)->toBe([]);
});
