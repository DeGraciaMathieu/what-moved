<?php

/*
 * Turns the graph.php results of the base and the head into the dependency graph of the pull request.
 * Usage: php report.php <base json> <head json> <config json> <output dir>. Writes:
 * - graph.svg: one circle per context filled with a dot per class, red when the pull request changed it, green when
 *   it added it, and only the relations it touches (added in green, existing ones from or to a touched class in grey);
 * - report.html: the same image, then every class and its dependencies to explore;
 * - summary.md: the figures for the pull request comment.
 * The contexts sit where the config file puts them, so the map stays the same from one pull request to the next.
 */

$read = fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
[, $basePath, $headPath, $configPath, $out] = $argv;
$config = (require __DIR__.'/config.php')($configPath);
$base = $read($basePath);
$head = $read($headPath);

if (! is_dir($out)) {
    mkdir($out, 0777, true);
}

// A class is added when the base does not have it, changed when its file differs.
$baseHashes = array_column($base['nodes'], 'hash', 'id');
$nodes = array_map(function (array $node) use ($baseHashes): array {
    $node['state'] = match (true) {
        ! isset($baseHashes[$node['id']]) => 'added',
        $baseHashes[$node['id']] !== $node['hash'] => 'changed',
        default => '',
    };

    return $node;
}, $head['nodes']);
$byId = array_column($nodes, null, 'id');
$removedClasses = count(array_diff_key($baseHashes, $byId));

$key = fn (array $edge): string => $edge[0].'>'.$edge[1];
$baseEdges = array_flip(array_map($key, $base['edges']));
$edges = array_map(fn (array $edge): array => [$edge[0], $edge[1], isset($baseEdges[$key($edge)]) ? 0 : 1], $head['edges']);
$removedEdges = count(array_diff_key($baseEdges, array_flip(array_map($key, $head['edges']))));
$addedEdges = array_sum(array_column($edges, 2));

$contexts = [];

foreach ($nodes as $node) {
    $contexts[$node['context']] ??= ['classes' => 0, 'hot' => 0, 'added' => true, 'internal' => 0, 'out' => 0];
    $contexts[$node['context']]['classes']++;
    $contexts[$node['context']]['hot'] += $node['state'] !== '' ? 1 : 0;
    $contexts[$node['context']]['added'] = $contexts[$node['context']]['added'] && $node['state'] === 'added';
}

$links = [];

foreach ($edges as [$from, $to, $isNew]) {
    $a = $byId[$from]['context'];
    $b = $byId[$to]['context'];

    if ($a === $b) {
        $contexts[$a]['internal']++;

        continue;
    }

    $contexts[$a]['out']++;
    $links["{$a}>{$b}"] ??= ['from' => $a, 'to' => $b, 'weight' => 0, 'added' => 0, 'touched' => 0];
    $links["{$a}>{$b}"]['weight']++;
    $links["{$a}>{$b}"]['added'] += $isNew;
    $links["{$a}>{$b}"]['touched'] += $byId[$from]['state'] !== '' || $byId[$to]['state'] !== '' ? 1 : 0;
}

// "0 classes", "1 class", "2 classes": only one takes the singular.
$plural = fn (int $count, string $one, string $many): string => $count === 1 ? $one : $many;

// Share of a context's dependencies that stay inside it.
$cohesion = fn (array $context): int => $context['internal'] + $context['out'] > 0
    ? (int) round(100 * $context['internal'] / ($context['internal'] + $context['out']))
    : 100;

// A context missing from the positions of the config goes next to the one it shares the most dependencies with.
$positions = $config['positions'];

foreach (array_keys($contexts) as $name) {
    if (isset($positions[$name])) {
        continue;
    }

    $neighbours = [];

    foreach ($links as $link) {
        if ($link['from'] === $name && isset($positions[$link['to']])) {
            $neighbours[$link['to']] = ($neighbours[$link['to']] ?? 0) + $link['weight'];
        } elseif ($link['to'] === $name && isset($positions[$link['from']])) {
            $neighbours[$link['from']] = ($neighbours[$link['from']] ?? 0) + $link['weight'];
        }
    }

    arsort($neighbours);
    [$x, $y] = $positions[array_key_first($neighbours) ?? ''] ?? [390, 270];
    $positions[$name] = [min(730, $x + 110), min(480, $y + 90)];
}

/*
 * The image.
 */

$e = fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_XML1);
$radius = fn (int $classes): float => 14 + sqrt($classes) * 10.5;
$ring = fn (string $name): float => $radius($contexts[$name]['classes']) + ($contexts[$name]['hot'] > 0 ? 9 : 4);
$colors = ['changed' => '#b5121b', 'added' => '#2f7d4f'];
$layerColors = array_column($config['layers'], 'color', 'name');

// A slightly bent edge from circle edge to circle edge.
$curve = function (array $from, array $to, float $r1, float $r2): string {
    [$x1, $y1] = $from;
    [$x2, $y2] = $to;
    $length = hypot($x2 - $x1, $y2 - $y1);
    $cx = ($x1 + $x2) / 2 - ($y2 - $y1) / $length * 0.08 * $length;
    $cy = ($y1 + $y2) / 2 + ($x2 - $x1) / $length * 0.08 * $length;
    $start = hypot($cx - $x1, $cy - $y1);
    $end = hypot($x2 - $cx, $y2 - $cy);

    return sprintf('M%.1f %.1f Q%.1f %.1f %.1f %.1f',
        $x1 + ($cx - $x1) / $start * $r1, $y1 + ($cy - $y1) / $start * $r1,
        $cx, $cy,
        $x2 - ($x2 - $cx) / $end * $r2, $y2 - ($y2 - $cy) / $end * $r2);
};

$touchedCount = count(array_filter($nodes, fn (array $node): bool => $node['state'] !== ''));
$svg = [];
$svg[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 780 610" width="780" height="610" font-family="-apple-system, \'Segoe UI\', Helvetica, Arial, sans-serif">';
$svg[] = '<style>text{fill:#1e1b16}.sub{fill:#6b6357;font-size:11px}.hot{fill:#b5121b;font-size:11px;font-weight:600}.new{fill:#2f7d4f;font-size:11px;font-weight:600}.legend{fill:#6b6357;font-size:12px}</style>';
$svg[] = '<defs>';

foreach (['edge' => '#a8998a', 'added' => '#2f7d4f'] as $id => $color) {
    $svg[] = "<marker id=\"arrow-{$id}\" viewBox=\"0 0 10 10\" refX=\"9\" refY=\"5\" markerUnits=\"userSpaceOnUse\" markerWidth=\"10\" markerHeight=\"10\" orient=\"auto-start-reverse\"><path d=\"M0 0 L10 5 L0 10 z\" fill=\"{$color}\"/></marker>";
}

$svg[] = '</defs>';
$svg[] = '<rect width="780" height="610" fill="#ffffff"/>';
$svg[] = sprintf('<text x="20" y="28" font-size="14" font-weight="600">%d %s touched out of %d · %d %s added</text>',
    $touchedCount, $plural($touchedCount, 'class', 'classes'), count($nodes),
    $addedEdges, $plural($addedEdges, 'dependency', 'dependencies'));

// An existing relation is drawn in grey at its full weight; what the pull request added goes over it in green,
// as thick as the dependencies it added.
foreach ($links as $link) {
    if ($link['added'] === 0 && $link['touched'] === 0) {
        continue;
    }

    $path = $curve($positions[$link['from']], $positions[$link['to']], $radius($contexts[$link['from']]['classes']) + 3, $ring($link['to']));
    $title = sprintf('<title>%s → %s: %d dependencies, %d added</title>', $e($link['from']), $e($link['to']), $link['weight'], $link['added']);

    if ($link['added'] < $link['weight']) {
        $svg[] = sprintf('<path d="%s" fill="none" stroke="#a8998a" stroke-width="%.1f" stroke-linecap="round" opacity="0.8" marker-end="url(#arrow-edge)">%s</path>',
            $path, 1 + sqrt($link['weight']) * 1.6, $title);
    }

    if ($link['added'] > 0) {
        $svg[] = sprintf('<path d="%s" fill="none" stroke="#2f7d4f" stroke-width="%.1f" stroke-linecap="round" marker-end="url(#arrow-added)">%s</path>',
            $path, 1 + sqrt($link['added']) * 1.6, $title);
    }
}

$rank = ['changed' => 0, 'added' => 0, '' => 1];
$layerRank = array_flip(array_column($config['layers'], 'name'));

foreach ($contexts as $name => $context) {
    [$x, $y] = $positions[$name];
    $r = $radius($context['classes']);

    if ($context['hot'] > 0) {
        $svg[] = sprintf('<circle cx="%d" cy="%d" r="%.1f" fill="none" stroke="#b5121b" stroke-width="3"/>', $x, $y, $r + 6);
        $svg[] = sprintf('<text x="%d" y="%.1f" text-anchor="middle" class="hot">hot:%d</text>', $x, $y - $r - 13, $context['hot']);
    }

    $svg[] = sprintf('<circle cx="%d" cy="%d" r="%.1f" fill="%s" stroke="%s" stroke-width="1.2"%s/>',
        $x, $y, $r, $context['added'] ? '#e3f1e8' : '#efe9dd', $context['added'] ? '#2f7d4f' : '#1e1b16', $context['added'] ? ' stroke-dasharray="4 3"' : '');

    // A sunflower spiral, the classes touched by the pull request first, so at the centre.
    $members = array_values(array_filter($nodes, fn (array $node): bool => $node['context'] === $name));
    usort($members, fn (array $a, array $b): int => [$rank[$a['state']], $layerRank[$a['layer']], $a['id']] <=> [$rank[$b['state']], $layerRank[$b['layer']], $b['id']]);
    $step = ($r - 9) / sqrt(count($members));

    foreach ($members as $i => $node) {
        $distance = $step * sqrt($i + 0.5);
        $svg[] = sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s" stroke="#ffffff" stroke-width="1"><title>%s</title></circle>',
            $x + $distance * cos($i * 2.39996), $y + $distance * sin($i * 2.39996), min(5.5, $step * 0.42),
            $node['state'] !== '' ? $colors[$node['state']] : $layerColors[$node['layer']], $e($node['id']));
    }

    $svg[] = sprintf('<text x="%d" y="%.1f" text-anchor="middle" font-size="12.5" font-weight="600">%s<tspan dx="6" class="%s">%s</tspan></text>',
        $x, $y + $r + 17, $e($name), $context['added'] ? 'new' : 'sub',
        $context['added'] ? 'new' : sprintf('%d cl. · cohesion %d %%', $context['classes'], $cohesion($context)));
}

$legend = [
    ...array_map(fn (array $layer): array => ['dot', $layer['color'], $layer['name']], $config['layers']),
    ['dot', $colors['changed'], 'changed'], ['dot', $colors['added'], 'added'], ['line', '#2f7d4f', 'dependency added'], ['line', '#a8998a', 'existing dependency touched'],
];
$x = 20;
$y = 562;

foreach ($legend as [$shape, $color, $label]) {
    $offset = $shape === 'dot' ? 14 : 24;
    $width = $offset + (int) (mb_strlen($label) * 6.4) + 16;

    if ($x + $width > 770) {
        $x = 20;
        $y += 22;
    }

    $svg[] = $shape === 'dot'
        ? sprintf('<circle cx="%d" cy="%d" r="5" fill="%s" stroke="#6b6357" stroke-width="0.5"/>', $x + 5, $y, $color)
        : sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="3"/>', $x, $y, $x + 18, $y, $color);
    $svg[] = sprintf('<text x="%d" y="%d" class="legend">%s</text>', $x + $offset, $y + 4, $e($label));
    $x += $width;
}

$svg[] = '</svg>';
$image = implode("\n", $svg);
file_put_contents("{$out}/graph.svg", $image."\n");

/*
 * The report to explore.
 */

$data = json_encode([
    'nodes' => array_map(fn (array $node): array => ['id' => $node['id'], 'context' => $node['context'], 'layer' => $node['layer'], 'state' => $node['state']], $nodes),
    'edges' => $edges,
    'positions' => $positions,
    'colors' => $colors,
    'layers' => $layerColors,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$report = file_get_contents(__DIR__.'/report.html');
file_put_contents("{$out}/report.html", strtr($report, ['{{image}}' => $image, '{{data}}' => $data]));

/*
 * The figures of the comment.
 */

$addedClasses = count(array_filter($nodes, fn (array $node): bool => $node['state'] === 'added'));
$hot = array_filter($contexts, fn (array $context): bool => $context['hot'] > 0);
ksort($hot);
$summary = ['<!-- what-moved -->', '### What moved', ''];

if ($hot === [] && $removedClasses === 0) {
    $summary[] = sprintf('This pull request touches no class in `%s/`.', trim(getenv('WHAT_MOVED_SOURCE'), '/'));
} else {
    $changedClasses = $touchedCount - $addedClasses;
    $summary[] = sprintf('**%d** %s added, **%d** changed, **%d** removed, out of %d · **%d** %s added, **%d** removed.',
        $addedClasses, $plural($addedClasses, 'class', 'classes'),
        $changedClasses,
        $removedClasses,
        count($nodes),
        $addedEdges, $plural($addedEdges, 'dependency', 'dependencies'),
        $removedEdges);
    $summary[] = '';
    $summary[] = 'Contexts touched: '.implode(', ', array_map(
        fn (string $name, array $context): string => sprintf('`%s` (%d%s)', $name, $context['hot'], $context['added'] ? ', new context' : ''),
        array_keys($hot), $hot,
    )).'.';
}

file_put_contents("{$out}/summary.md", implode("\n", $summary)."\n");
