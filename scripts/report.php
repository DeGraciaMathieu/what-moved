<?php

/*
 * Turns the graph.php results of the base and the head into the dependency graph of the pull request.
 * Usage: php report.php <base json> <head json> <config json> <output dir>. Writes:
 * - graph.svg: one circle per context, sized by its classes, ringed by a gauge of the share of them the pull request
 *   changed (red) or added (green), and only the relations it touches (added in green, existing ones from or to
 *   a touched class in grey);
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
    $contexts[$node['context']] ??= ['classes' => 0, 'hot' => 0, 'changedClasses' => 0, 'addedClasses' => 0, 'added' => true, 'internal' => 0, 'out' => 0];
    $contexts[$node['context']]['classes']++;
    $contexts[$node['context']]['hot'] += $node['state'] !== '' ? 1 : 0;
    $contexts[$node['context']]['changedClasses'] += $node['state'] === 'changed' ? 1 : 0;
    $contexts[$node['context']]['addedClasses'] += $node['state'] === 'added' ? 1 : 0;
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

// A context without a position goes next to the one it shares the most dependencies with.
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
$radius = fn (int $classes): float => 10 + sqrt($classes) * 7;
$ring = fn (string $name): float => $radius($contexts[$name]['classes']) + ($contexts[$name]['hot'] > 0 ? 10 : 4);
$colors = ['changed' => '#b5121b', 'added' => '#2f7d4f'];

// What an edge should go around: every circle with its gauge, and the texts above and under it.
$obstacles = [];

foreach ($contexts as $name => $context) {
    [$x, $y] = $positions[$name];
    $r = $radius($context['classes']);
    $label = mb_strlen($name) * 8 + ($context['added'] ? 30 : 90);
    $obstacles[] = ['context' => $name, 'circle' => [$x, $y, $ring($name) + 4]];
    $obstacles[] = ['context' => null, 'box' => [$x - $label / 2, $y + $r + 11, $x + $label / 2, $y + $r + 29]];

    if ($context['hot'] > 0) {
        $obstacles[] = ['context' => null, 'box' => [$x - 55, $y - $r - 39, $x + 55, $y - $r - 21]];
    }
}

// The bend of an edge: among a few on each side, the one that crosses the fewest obstacles, the straightest first.
$bend = function (array $from, array $to, string $a, string $b) use ($obstacles): float {
    [$x1, $y1] = $from;
    [$x2, $y2] = $to;
    $best = [PHP_INT_MAX, 0.08];

    foreach ([0.08, -0.08, 0.18, -0.18, 0.3, -0.3, 0.42, -0.42] as $k) {
        $cx = ($x1 + $x2) / 2 - ($y2 - $y1) * $k;
        $cy = ($y1 + $y2) / 2 + ($x2 - $x1) * $k;
        $hits = 0;

        for ($t = 0.04; $t < 0.97; $t += 0.03) {
            $px = (1 - $t) ** 2 * $x1 + 2 * (1 - $t) * $t * $cx + $t ** 2 * $x2;
            $py = (1 - $t) ** 2 * $y1 + 2 * (1 - $t) * $t * $cy + $t ** 2 * $y2;

            foreach ($obstacles as $obstacle) {
                if (isset($obstacle['circle'])) {
                    [$ox, $oy, $or] = $obstacle['circle'];
                    $hits += ! in_array($obstacle['context'], [$a, $b], true) && hypot($px - $ox, $py - $oy) < $or ? 1 : 0;
                } else {
                    [$left, $top, $right, $bottom] = $obstacle['box'];
                    $hits += $px > $left && $px < $right && $py > $top && $py < $bottom ? 1 : 0;
                }
            }
        }

        $score = $hits + abs($k) * 4;

        if ($score < $best[0]) {
            $best = [$score, $k];
        }
    }

    return $best[1];
};

// A bent edge from circle edge to circle edge.
$curve = function (array $from, array $to, float $r1, float $r2, float $k): string {
    [$x1, $y1] = $from;
    [$x2, $y2] = $to;
    $cx = ($x1 + $x2) / 2 - ($y2 - $y1) * $k;
    $cy = ($y1 + $y2) / 2 + ($x2 - $x1) * $k;
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
$svg[] = '<style>text{fill:#1e1b16}.sub{fill:#6b6357;font-size:11px}.hot{fill:#b5121b;font-size:11px;font-weight:600}.new{fill:#2f7d4f;font-size:11px;font-weight:600}.legend{fill:#6b6357;font-size:12px}.count{fill:#6b6357;font-size:14px;font-weight:600}</style>';
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
// as thick as the dependencies it added. The arrowheads are drawn again over the texts, which may hide them.
$tips = [];

foreach ($links as $link) {
    if ($link['added'] === 0 && $link['touched'] === 0) {
        continue;
    }

    $from = $positions[$link['from']];
    $to = $positions[$link['to']];
    $path = $curve($from, $to, $radius($contexts[$link['from']]['classes']) + 3, $ring($link['to']), $bend($from, $to, $link['from'], $link['to']));
    $title = sprintf('<title>%s → %s: %d dependencies, %d added</title>', $e($link['from']), $e($link['to']), $link['weight'], $link['added']);

    if ($link['added'] < $link['weight']) {
        $svg[] = sprintf('<path d="%s" fill="none" stroke="#a8998a" stroke-width="%.1f" stroke-linecap="round" opacity="0.8" marker-end="url(#arrow-edge)">%s</path>',
            $path, 1 + sqrt($link['weight']) * 1.6, $title);
    }

    if ($link['added'] > 0) {
        $svg[] = sprintf('<path d="%s" fill="none" stroke="#2f7d4f" stroke-width="%.1f" stroke-linecap="round" marker-end="url(#arrow-added)">%s</path>',
            $path, 1 + sqrt($link['added']) * 1.6, $title);
    }

    $tips[] = sprintf('<path d="%s" fill="none" stroke="none" marker-end="url(#arrow-%s)"/>', $path, $link['added'] > 0 ? 'added' : 'edge');
}

// A plate under a text centred on x, so the edges passing behind it do not run through the letters.
$plate = fn (float $x, float $baseline, float $width, string $fill = '#ffffff'): string => sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="17" rx="3" fill="%s" opacity="0.92"/>',
    $x - $width / 2 - 5, $baseline - 12.5, $width + 10, $fill);

foreach ($contexts as $name => $context) {
    [$x, $y] = $positions[$name];
    $r = $radius($context['classes']);

    // A gauge around a touched context: the red arc covers the share of its classes the pull request changed,
    // the green one, right after, the share it added.
    if ($context['hot'] > 0) {
        $length = 2 * M_PI * ($r + 6);
        $changed = $length * $context['changedClasses'] / $context['classes'];
        $added = $length * $context['addedClasses'] / $context['classes'];
        $svg[] = sprintf('<circle cx="%d" cy="%d" r="%.1f" fill="none" stroke="#e6dfd2" stroke-width="5"/>', $x, $y, $r + 6);
        if ($changed > 0) {
            $svg[] = sprintf('<circle cx="%d" cy="%d" r="%.1f" fill="none" stroke="#b5121b" stroke-width="5" stroke-dasharray="%.1f %.1f" transform="rotate(-90 %d %d)"/>',
                $x, $y, $r + 6, $changed, $length, $x, $y);
        }

        if ($added > 0) {
            $svg[] = sprintf('<circle cx="%d" cy="%d" r="%.1f" fill="none" stroke="#2f7d4f" stroke-width="5" stroke-dasharray="%.1f %.1f" stroke-dashoffset="%.1f" transform="rotate(-90 %d %d)"/>',
                $x, $y, $r + 6, $added, $length, -$changed, $x, $y);
        }

        $count = match (true) {
            $context['addedClasses'] === 0 => sprintf('%d of %d changed', $context['changedClasses'], $context['classes']),
            $context['changedClasses'] === 0 => sprintf('%d of %d added', $context['addedClasses'], $context['classes']),
            default => sprintf('%d changed · %d added of %d', $context['changedClasses'], $context['addedClasses'], $context['classes']),
        };
        // High enough over the gauge for an arrowhead coming from above to fit between them.
        $svg[] = $plate($x, $y - $r - 26, mb_strlen($count) * 6.3, $changed > 0 ? '#fbe8e8' : '#e3f1e8');
        $svg[] = sprintf('<text x="%d" y="%.1f" text-anchor="middle" class="%s">%s</text>', $x, $y - $r - 26, $changed > 0 ? 'hot' : 'new', $e($count));
    }

    $svg[] = sprintf('<circle cx="%d" cy="%d" r="%.1f" fill="%s" stroke="%s" stroke-width="1.2"%s/>',
        $x, $y, $r, $context['added'] ? '#e3f1e8' : '#efe9dd', $context['added'] ? '#2f7d4f' : '#1e1b16', $context['added'] ? ' stroke-dasharray="4 3"' : '');
    $svg[] = sprintf('<text x="%d" y="%.1f" text-anchor="middle" class="count">%d</text>', $x, $y + 5, $context['classes']);
    $detail = $context['added'] ? 'new' : sprintf('cohesion %d %%', $cohesion($context));
    $svg[] = $plate($x, $y + $r + 24, mb_strlen($name) * 7.4 + 6 + mb_strlen($detail) * 6.4);
    $svg[] = sprintf('<text x="%d" y="%.1f" text-anchor="middle" font-size="12.5" font-weight="600">%s<tspan dx="6" class="%s">%s</tspan></text>',
        $x, $y + $r + 24, $e($name), $context['added'] ? 'new' : 'sub', $detail);
}

array_push($svg, ...$tips);

$legend = [['gauge', $colors['changed'], 'classes changed'], ['gauge', $colors['added'], 'classes added'], ['line', '#2f7d4f', 'dependency added'], ['line', '#a8998a', 'existing dependency touched']];
$x = 20;
$y = 562;

foreach ($legend as [$shape, $color, $label]) {
    $offset = $shape === 'gauge' ? 16 : 24;
    $width = $offset + (int) (mb_strlen($label) * 6.4) + 16;

    if ($x + $width > 770) {
        $x = 20;
        $y += 22;
    }

    $svg[] = $shape === 'gauge'
        ? sprintf('<circle cx="%d" cy="%d" r="5" fill="none" stroke="%s" stroke-width="3"/>', $x + 5, $y, $color)
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
    'nodes' => array_map(fn (array $node): array => ['id' => $node['id'], 'context' => $node['context'], 'state' => $node['state']], $nodes),
    'edges' => $edges,
    'positions' => $positions,
    'colors' => $colors,
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
