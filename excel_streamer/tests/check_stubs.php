<?php

declare(strict_types=1);

// Verify that a hand-written stub file matches the real extension API.
// Usage: php check_stubs.php <stub.php> <ClassName>
// Must run with the extension loaded so ReflectionClass works.

[$script, $stub, $class] = $argv + [null, null, null];
if ($stub === null || $class === null) {
    fwrite(STDERR, "usage: php check_stubs.php <stub.php> <ClassName>\n");
    exit(2);
}
if (!is_file($stub)) {
    fwrite(STDERR, "stub file not found: $stub\n");
    exit(2);
}
if (!class_exists($class)) {
    fwrite(STDERR, "class not loaded (extension missing?): $class\n");
    exit(2);
}

$src = file_get_contents($stub);
preg_match_all('/\bpublic\s+(?:static\s+)?function\s+(\w+)\s*\(([^)]*)\)/', $src, $stubMethods);

$reflection = new ReflectionClass($class);
$real = [];
foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
    $real[$m->getName()] = count($m->getParameters());
}

$errors = 0;
foreach ($stubMethods[1] as $i => $name) {
    if (!array_key_exists($name, $real)) {
        fwrite(STDERR, "stub declares unknown method: $class::$name()\n");
        $errors++;
        continue;
    }
    $paramSrc = preg_replace("/'[^']*'/", "''", $stubMethods[2][$i]);
    $paramSrc = preg_replace('/"[^"]*"/', '""', $paramSrc);
    $stubCount = $paramSrc === '' ? 0 : count(explode(',', $paramSrc));
    if ($stubCount !== $real[$name]) {
        fwrite(STDERR, "param mismatch: $class::$name() stub=$stubCount real={$real[$name]}\n");
        $errors++;
    }
    unset($real[$name]);
}

if ($real !== []) {
    fwrite(STDERR, 'stub missing methods: ' . implode(', ', array_keys($real)) . "\n");
    $errors += count($real);
}

if ($errors > 0) {
    fwrite(STDERR, "STUB CHECK FAILED: $stub ($errors issue(s))\n");
    exit(1);
}
echo "STUB CHECK OK: $stub\n";
