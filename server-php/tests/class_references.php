<?php

declare(strict_types=1);

/**
 * Every class a file in src/ (or an entry point: index.php, bin/, config/) names resolves to a class
 * that exists.
 *
 * PHP resolves `Foo::bar()`, `new Foo` and `catch (Foo $e)` against the file's own namespace and
 * its `use` imports only when that line runs, so a missing import is not a lint error: it is a 500
 * on the one path that reaches it. EstateAnalytics::forContext() named from Controllers\ without
 * `use Aicountly\Api\EstateAnalytics` answered every channel creation with 500 after the row was
 * written, and would have done the same after every message a provider accepted.
 *
 * Reads the tokens of each src/ file (vendored copy-don't-edit folders excluded), resolves each
 * referenced name the way PHP does, and asks the autoloader for every name in this product's own
 * namespace. No database, no network.
 *
 *   php server-php/tests/class_references.php
 */

$root = dirname(__DIR__);
require $root . '/src/Env.php';
require $root . '/src/Autoload.php';

const OWN_PREFIX = 'Aicountly\\Api\\';
// Copied verbatim from other repositories, loaded with require_once, in their own namespaces.
const VENDORED = ['/src/EstateAnalytics/', '/src/Clients/vendor-contacts-client/'];

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (!str_ends_with($path, '.php')) {
        continue;
    }
    foreach (VENDORED as $skip) {
        if (str_contains($path, $skip)) {
            continue 2;
        }
    }
    $files[] = $path;
}
sort($files);

// The entry points name src/ classes too: the front controller, the CLI workers and the kit
// bootstrap. Their references are checked; they are never loaded (they run when required).
$entryPoints = [$root . '/index.php'];
foreach (['bin', 'config'] as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getPathname(), '.php')) {
            $entryPoints[] = str_replace('\\', '/', $file->getPathname());
        }
    }
}
sort($entryPoints);

/**
 * @return list<array{name: string, line: int}> every class reference in $path, resolved
 */
function class_references(string $path): array
{
    $tokens = array_values(array_filter(
        token_get_all((string) file_get_contents($path)),
        static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $namespace = '';
    $imports = [];
    $depth = 0;
    $refs = [];
    $n = count($tokens);

    $resolve = static function (array $token) use (&$namespace, &$imports): ?string {
        [$kind, $text] = $token;
        if ($kind === T_NAME_FULLY_QUALIFIED) {
            return ltrim($text, '\\');
        }
        if (in_array(strtolower($text), ['self', 'static', 'parent'], true)) {
            return null;
        }
        $first = explode('\\', $text)[0];
        if (isset($imports[strtolower($first)])) {
            return $imports[strtolower($first)] . substr($text, strlen($first));
        }

        return ($namespace !== '' ? $namespace . '\\' : '') . $text;
    };
    $isName = static fn ($t): bool => is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
            continue;
        }
        if ($t === '}') {
            $depth--;
            continue;
        }
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_NAMESPACE && $isName($tokens[$i + 1] ?? null)) {
            $namespace = ltrim($tokens[$i + 1][1], '\\');
            $imports = [];
            continue;
        }
        // Top-level class imports only: a `use` inside a class body is a trait, `use function` /
        // `use const` import no class.
        if ($t[0] === T_USE && $depth === 0) {
            $next = $tokens[$i + 1] ?? null;
            if (is_array($next) && in_array($next[0], [T_FUNCTION, T_CONST], true)) {
                continue;
            }
            for ($j = $i + 1; $j < $n && $tokens[$j] !== ';'; $j++) {
                if (!$isName($tokens[$j])) {
                    continue;
                }
                $full = ltrim($tokens[$j][1], '\\');
                $alias = substr($full, (int) strrpos('\\' . $full, '\\'));
                if (is_array($tokens[$j + 1] ?? null) && $tokens[$j + 1][0] === T_AS) {
                    $alias = $tokens[$j + 2][1];
                    $j += 2;
                }
                $imports[strtolower($alias)] = $full;
            }
            $i = $j;
            continue;
        }
        if (!$isName($t)) {
            continue;
        }
        $prev = $tokens[$i - 1] ?? null;
        $next = $tokens[$i + 1] ?? null;
        $isRef = (is_array($next) && $next[0] === T_DOUBLE_COLON)
            || (is_array($prev) && in_array($prev[0], [T_NEW, T_INSTANCEOF], true))
            || ($prev === '(' && is_array($tokens[$i - 2] ?? null) && $tokens[$i - 2][0] === T_CATCH);
        if (!$isRef) {
            continue;
        }
        $name = $resolve($t);
        if ($name !== null) {
            $refs[] = ['name' => $name, 'line' => $t[2]];
        }
    }

    return $refs;
}

// Load every file's own class first, so a class that require_once's a vendored dependency at the top
// of its file has done so before its references are checked. Only files that declare one: Env.php,
// Autoload.php, front.php and the CLI scripts are not classes, and asking the autoloader for one
// would run it.
// A copy-don't-edit file in another namespace (Metering/AicountlyUsageMeter.php) is not asked for
// either: its owner loads it with require_once, which the autoloader's plain require would break.
foreach ($files as $path) {
    $relative = substr($path, strlen($root . '/src/'), -4);
    $class = OWN_PREFIX . str_replace('/', '\\', $relative);
    $source = (string) file_get_contents($path);
    $namespace = preg_match('/^namespace\s+([^;]+);/m', $source, $m) === 1 ? trim($m[1]) : '';
    $short = substr($class, (int) strrpos($class, '\\') + 1);
    $declaresIt = preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+' . preg_quote($short, '/') . '\b/m', $source) === 1;
    if ($declaresIt && $namespace . '\\' . $short === $class) {
        class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class);
    }
}

$checked = 0;
$missing = [];
foreach (array_merge($files, $entryPoints) as $path) {
    foreach (class_references($path) as $ref) {
        if (!str_starts_with($ref['name'], OWN_PREFIX)) {
            continue;
        }
        $checked++;
        $name = $ref['name'];
        if (!(class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name))) {
            $missing[] = sprintf('%s:%d names %s, which does not exist (a missing `use`?)',
                substr($path, strlen($root) + 1), $ref['line'], $name);
        }
    }
}

echo "class references\n";
if ($missing === []) {
    echo "  ok    every class named in src/, bin/, config/ and index.php resolves ({$checked} references in "
        . (count($files) + count($entryPoints)) . " files)\n";
    echo "\n============================================================\n1 passed, 0 failed\n";
    exit(0);
}
echo "  FAIL  every class named in src/, bin/, config/ and index.php resolves\n";
foreach ($missing as $line) {
    echo "        {$line}\n";
}
echo "\n============================================================\n0 passed, 1 failed\n";
exit(1);
