<?php

declare(strict_types=1);

/**
 * Development helper: parse-check a list of PHP files.
 *
 * Used by tools/dev/php.mjs so that "lint passed" means the PHP parser actually
 * accepted the file - not that a command exited zero. A checker that cannot fail is
 * worse than no checker (see the QA rule), so this script exits non-zero on the first
 * unparseable file and names it.
 *
 * Usage:  php tools/dev/lint-files.php '["/path/a.php","/path/b.php"]'
 */

$json = $argv[1] ?? '[]';
$files = json_decode($json, true);

if (!is_array($files)) {
    fwrite(STDERR, "lint-files: expected a JSON array of file paths\n");
    exit(2);
}

$failures = 0;
foreach ($files as $file) {
    if (!is_string($file) || !is_file($file)) {
        fwrite(STDERR, "lint-files: not a file: " . (is_string($file) ? $file : gettype($file)) . "\n");
        $failures++;
        continue;
    }

    $source = (string) file_get_contents($file);
    try {
        // TOKEN_PARSE runs the real parser, which is what `php -l` does.
        token_get_all($source, TOKEN_PARSE);
    } catch (\ParseError $e) {
        $failures++;
        fwrite(STDOUT, sprintf(
            "PARSE ERROR  %s\n             %s (line %d)\n",
            $file,
            $e->getMessage(),
            $e->getLine(),
        ));
    }
}

fwrite(STDOUT, sprintf("checked %d file(s), %d failed\n", count($files), $failures));

exit($failures === 0 ? 0 : 1);
