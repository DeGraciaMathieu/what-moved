<?php

/*
 * Reads the configuration file of the project: the declared contexts with their prefixes and their place on the map,
 * the excluded prefixes, the layers, and the layer of the classes no layer recognises.
 * A missing file or key falls back to the defaults below, and a null position to no position.
 * The positions are also gathered by context, for the map.
 * Usage: $config = (require __DIR__.'/config.php')($path);
 */

return function (string $path): array {
    $config = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];

    $config += [
        'contexts' => [],
        'exclude' => [],
        'layers' => [
            ['name' => 'Domain', 'color' => '#1e1b16', 'segments' => ['Domain']],
            ['name' => 'Application', 'color' => '#6b6357', 'segments' => ['Application']],
            ['name' => 'Infrastructure', 'color' => '#a8998a', 'segments' => ['Infrastructure']],
            ['name' => 'Http', 'color' => '#d9d1c2', 'contexts' => ['Http']],
        ],
        'default-layer' => 'Application',
    ];

    $config['contexts'] = array_map(fn (array $context): array => $context + ['prefixes' => [], 'position' => null], $config['contexts']);
    $config['positions'] = array_filter(array_map(fn (array $context): ?array => $context['position'], $config['contexts']), fn (?array $position): bool => $position !== null);

    $config['layers'] = array_map(fn (array $layer): array => $layer + ['segments' => [], 'contexts' => []], $config['layers']);

    if (! in_array($config['default-layer'], array_column($config['layers'], 'name'), true)) {
        throw new InvalidArgumentException("The default layer \"{$config['default-layer']}\" is not one of the layers of {$path}.");
    }

    return $config;
};
