<?php

declare(strict_types=1);

/**
 * Shortens over-long derived index names in a Laravel application's migrations.
 *
 * Laravel derives an index name from the table plus every column plus the type.
 * MySQL and MariaDB cap identifiers at 64 characters, PostgreSQL at 63. Where
 * the derived name is longer, `migrate` fails PARTWAY THROUGH — leaving a
 * half-created schema, which is worse than failing at the start.
 *
 * The rewrite is mechanical and lossless: index names carry no meaning, only
 * uniqueness within their table. Each affected index gets an explicit,
 * deterministic short name, so re-running after an upgrade produces an
 * identical schema rather than a second set of indexes.
 *
 * Usage: php fix-index-names.php <app-dir> [--check]
 */

$target = $argv[1] ?? '';
$check = in_array('--check', $argv, true);

if ($target === '' || ! is_dir($target)) {
    fwrite(STDERR, "Usage: fix-index-names.php <app-dir> [--check]\n");
    exit(2);
}

/** The stricter of MySQL (64) and PostgreSQL (63), so one pass satisfies both. */
const LIMIT = 63;

$files = array_merge(
    glob($target.'/database/migrations/*.php') ?: [],
    glob($target.'/modules/*/database/migrations/*.php') ?: [],
    glob($target.'/vendor/*/*/database/migrations/*.php') ?: [],
);

$found = 0;
$patched = 0;
$examples = [];

foreach ($files as $file) {
    $source = (string) file_get_contents($file);
    $original = $source;

    // A migration file may create SEVERAL tables. Walk it in order and track
    // which table each index belongs to — deriving the name from the wrong
    // table silently leaves the real offender unpatched.
    // Three things are matched: a table declaration (so we know which table
    // the following indexes belong to), a derived index, and a derived foreign
    // key. Foreign keys hit the same limit and fail the same way.
    $pattern = '/Schema::(?:create|table)\(\s*[\'"]([a-z0-9_]+)[\'"]'
        .'|->(unique|index)\(\s*(\[[^\]]*\])\s*\)'
        .'|->foreignId\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\)((?:\s*->\w+\([^()]*\))*?\s*->constrained\(([^()]*)\))/';

    $offset = 0;
    $out = '';
    $table = null;

    while (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $start = $m[0][1];
        $out .= substr($source, $offset, $start - $offset);
        $offset = $start + strlen($m[0][0]);

        if (($m[1][0] ?? '') !== '') {
            $table = $m[1][0];
            $out .= $m[0][0];

            continue;
        }

        // Foreign key: ->foreignId('x')...->constrained(...)
        if (($m[4][0] ?? '') !== '' && $table !== null) {
            $column = $m[4][0];
            $derived = $table.'_'.$column.'_foreign';
            $existingArgs = trim($m[6][0] ?? '');

            // Already named: constrained(table, column, indexName). Re-deriving
            // would report a patched file as still broken, and --check would
            // never go green.
            $alreadyNamed = substr_count($existingArgs, ',') >= 2;

            if ($alreadyNamed || strlen($derived) <= LIMIT) {
                $out .= $m[0][0];

                continue;
            }

            $found++;
            if (count($examples) < 8) {
                $examples[] = basename($file).': '.$derived.' ('.strlen($derived).')';
            }

            if ($check) {
                $out .= $m[0][0];

                continue;
            }

            $short = substr($table, 0, 38).'_'.substr(sha1($derived), 0, 12).'_fk';

            // constrained(table, column, indexName) — supply all three so the
            // name lands in the right position whatever was already given.
            $args = $existingArgs === ''
                ? "null, 'id', '".$short."'"
                : ($existingArgs.", 'id', '".$short."'");

            // Only the trailing ->constrained(...) is rewritten; any modifiers
            // between foreignId() and it are preserved verbatim.
            $chain = (string) $m[5][0];
            $rewritten = preg_replace(
                '/->constrained\([^()]*\)$/',
                "->constrained(".$args.")",
                $chain,
            );

            $out .= "->foreignId('".$column."')".$rewritten;
            $patched++;

            continue;
        }

        $kind = $m[2][0];
        $columnsLiteral = $m[3][0];
        $columns = preg_replace('/[\'"\s]/', '', trim($columnsLiteral, '[]')) ?? '';
        $derived = ($table ?? 'unknown').'_'.str_replace(',', '_', $columns).'_'.$kind;

        if ($kind === '' || $table === null || strlen($derived) <= LIMIT) {
            $out .= $m[0][0];

            continue;
        }

        $found++;
        if (count($examples) < 5) {
            $examples[] = basename($file).': '.$derived.' ('.strlen($derived).')';
        }

        if ($check) {
            $out .= $m[0][0];

            continue;
        }

        $short = substr($table, 0, 38).'_'.substr(sha1($derived), 0, 12).'_'.($kind === 'unique' ? 'uq' : 'ix');
        $out .= '->'.$kind.'('.$columnsLiteral.", '".$short."')";
        $patched++;
    }

    $out .= substr($source, $offset);

    if (! $check && $out !== $original) {
        if (! file_exists($file.'.orig')) {
            copy($file, $file.'.orig');
        }
        file_put_contents($file, $out);
    }
}

printf("Przeskanowano plikow migracji: %d\n", count($files));
printf("Nazw indeksow dluzszych niz %d znakow: %d\n", LIMIT, $found);

foreach ($examples as $example) {
    printf("  %s\n", $example);
}

if ($check) {
    printf("%s\n", $found === 0
        ? 'OK - wszystkie nazwy indeksow miesza sie w limicie.'
        : 'WYMAGA POPRAWKI - uruchom bez --check.');

    exit($found === 0 ? 0 : 1);
}

printf("Poprawiono: %d\n", $patched);
exit(0);
