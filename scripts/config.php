<?php

/*
 * Reads the configuration file of the project: the declared contexts with their prefixes and their place on the map,
 * the excluded prefixes, and whether the map shows every context or only the ones the pull request moved.
 * A missing file or key falls back to the defaults below, and a null position to no position.
 * The positions are also gathered by context, for the map.
 * Usage: $config = (require __DIR__.'/config.php')($path);
 */

return function (string $path): array {
    $config = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];

    $config += [
        'contexts' => [],
        'exclude' => [],
        'show-all-contexts' => false,
    ];

    $config['contexts'] = array_map(fn (array $context): array => $context + ['prefixes' => [], 'position' => null], $config['contexts']);
    $config['positions'] = array_filter(array_map(fn (array $context): ?array => $context['position'], $config['contexts']), fn (?array $position): bool => $position !== null);

    return $config;
};
