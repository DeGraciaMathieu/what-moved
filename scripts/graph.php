<?php

/*
 * Lists the classes of the source folder and the classes of the source folder each one uses.
 * Usage: php graph.php <project root> <config json>. Prints JSON: nodes (id, context, layer, file, hash) and edges ([from, to]).
 * It runs against both the base and the head of the pull request with the same parser, the one of this action.
 * A class counts as used when its name appears in the code once resolved: a type, a new, a static call,
 * an instanceof, an attribute, a ::class. Imports alone and docblocks do not count.
 * The settings come from the environment, filled by action.yml from the inputs, and the layers from the config file.
 */

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require __DIR__.'/../vendor/autoload.php';

$list = fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));

$root = rtrim($argv[1], '/');
$config = (require __DIR__.'/config.php')($argv[2]);
$source = trim(getenv('WHAT_MOVED_SOURCE'), '/');
$namespace = trim(getenv('WHAT_MOVED_NAMESPACE'), '\\').'\\';
$excluded = $list(getenv('WHAT_MOVED_EXCLUDE'));

// "Policies:Http" puts the classes of Policies in the Http context.
$merged = [];

foreach ($list(getenv('WHAT_MOVED_MERGE')) as $pair) {
    [$from, $to] = array_map('trim', explode(':', $pair, 2));
    $merged[$from] = $to;
}

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;

// The context is the first segment after the root namespace.
$context = function (string $class) use ($namespace, $excluded, $merged): ?string {
    if (! str_starts_with($class, $namespace)) {
        return null;
    }

    $segment = explode('\\', substr($class, strlen($namespace)))[0];

    return in_array($segment, $excluded, true) ? null : ($merged[$segment] ?? $segment);
};

// The first layer that lists the context of the class, or one of the segments between the context and the class name.
$layer = function (string $class, string $context) use ($namespace, $config): string {
    $segments = array_slice(explode('\\', substr($class, strlen($namespace))), 1, -1);

    foreach ($config['layers'] as $candidate) {
        if (in_array($context, $candidate['contexts'], true) || array_intersect($segments, $candidate['segments']) !== []) {
            return $candidate['name'];
        }
    }

    return $config['default-layer'];
};

$classes = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$source, FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $code = file_get_contents($file->getPathname());
    $traverser = new NodeTraverser(new NameResolver);
    $ast = $traverser->traverse($parser->parse($code));

    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $declaration) {
        if ($declaration->namespacedName === null) {
            continue;
        }

        $id = $declaration->namespacedName->toString();
        $names = array_map(fn (Node\Name $name): string => $name->toString(), $finder->findInstanceOf($declaration, Node\Name\FullyQualified::class));

        $classes[$id] = [
            'file' => substr($file->getPathname(), strlen($root) + 1),
            'hash' => sha1($code),
            'uses' => array_unique($names),
        ];
    }
}

$nodes = [];
$edges = [];

foreach ($classes as $id => $class) {
    $from = $context($id);

    if ($from === null) {
        continue;
    }

    $nodes[] = ['id' => $id, 'context' => $from, 'layer' => $layer($id, $from), 'file' => $class['file'], 'hash' => $class['hash']];

    foreach ($class['uses'] as $used) {
        if ($used !== $id && isset($classes[$used]) && $context($used) !== null) {
            $edges[] = [$id, $used];
        }
    }
}

usort($nodes, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
sort($edges);

echo json_encode(['nodes' => $nodes, 'edges' => $edges], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
