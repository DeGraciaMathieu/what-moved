<?php

/*
 * Lists the classes of the source folder and the classes of the source folder each one uses.
 * Usage: php graph.php <project root> <config json>. Prints JSON: nodes (id, context, file, hash) and edges ([from, to]).
 * It runs against both the base and the head of the pull request with the same parser, the one of this action.
 * A class counts as used when its name appears in the code once resolved: a type, a new, a static call,
 * an instanceof, an attribute, a ::class. Imports alone and docblocks do not count.
 * The source folder and the root namespace come from the environment, filled by action.yml from the inputs;
 * the contexts come from the config file.
 */

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require __DIR__.'/../vendor/autoload.php';

$root = rtrim($argv[1], '/');
$config = (require __DIR__.'/config.php')($argv[2]);
$source = trim(getenv('WHAT_MOVED_SOURCE'), '/');
$namespace = trim(getenv('WHAT_MOVED_NAMESPACE'), '\\').'\\';

// Each declared prefix and its context, the longest first so that Carnet\Notes wins over Carnet.
$prefixes = [];

foreach ($config['contexts'] as $name => $declared) {
    foreach ($declared['prefixes'] as $prefix) {
        $prefixes[trim($prefix, '\\')] = $name;
    }
}

uksort($prefixes, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
$matches = fn (string $relative, string $prefix): bool => $relative === $prefix || str_starts_with($relative, $prefix.'\\');

// The context of a class, from its name without the root namespace: the one of the longest declared prefix that
// matches it, or else its first segment.
$context = function (string $class) use ($namespace, $config, $prefixes, $matches): ?string {
    if (! str_starts_with($class, $namespace)) {
        return null;
    }

    $relative = substr($class, strlen($namespace));

    foreach ($config['exclude'] as $prefix) {
        if ($matches($relative, trim($prefix, '\\'))) {
            return null;
        }
    }

    foreach ($prefixes as $prefix => $name) {
        if ($matches($relative, $prefix)) {
            return $name;
        }
    }

    return explode('\\', $relative)[0];
};

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;

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

    $nodes[] = ['id' => $id, 'context' => $from, 'file' => $class['file'], 'hash' => $class['hash']];

    foreach ($class['uses'] as $used) {
        if ($used !== $id && isset($classes[$used]) && $context($used) !== null) {
            $edges[] = [$id, $used];
        }
    }
}

usort($nodes, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
sort($edges);

echo json_encode(['nodes' => $nodes, 'edges' => $edges], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
