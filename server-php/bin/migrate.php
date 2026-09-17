<?php

declare(strict_types=1);

/**
 * Apply pending SQL migrations.
 *
 *   php server-php/bin/migrate.php            apply everything not yet applied
 *   php server-php/bin/migrate.php --status   list what would run, change nothing
 *
 * Each file is applied inside its own transaction and recorded by name, in
 * filename order (hence the numeric prefixes). There is no --dry-run here:
 * MySQL/InnoDB commits DDL (CREATE TABLE and friends) immediately regardless
 * of any surrounding transaction, so a rollback cannot honestly undo it —
 * unlike Pay's Postgres migrator, which can.
 *
 * There is nothing in database/migrations/ yet. This ships ahead of any
 * schema so the first feature that needs one can add
 * database/migrations/001_....sql and run this, rather than inventing a
 * migration mechanism under deadline.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Db.php';

Env::load(__DIR__ . '/../.env');

$args   = array_slice($argv, 1);
$status = in_array('--status', $args, true);

$dir = __DIR__ . '/../database/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, "No migrations found in {$dir}\n");
    exit(1);
}

try {
    $pdo = Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect: {$e->getMessage()}\n");
    exit(1);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS messaging_sql_migrations (
        migration_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename     VARCHAR(255) NOT NULL UNIQUE,
        checksum     CHAR(64)     NOT NULL,
        applied_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
     ) ENGINE=InnoDB',
);

$applied = [];
foreach (Db::all('SELECT filename, checksum FROM messaging_sql_migrations') as $row) {
    $applied[$row['filename']] = $row['checksum'];
}

$pending = 0;
$drift   = 0;

foreach ($files as $path) {
    $name = basename($path);
    $sql = (string) file_get_contents($path);
    $checksum = hash('sha256', $sql);

    if (isset($applied[$name])) {
        if ($applied[$name] !== $checksum) {
            // An applied migration that has since been edited. Reapplying it
            // would not undo what the old version did; say so and let a
            // human decide, rather than guessing.
            fwrite(STDERR, "CHANGED  {$name} — already applied, but the file has been edited since.\n");
            $drift++;
        } elseif ($status) {
            echo "already  {$name}\n";
        }
        continue;
    }

    $pending++;
    if ($status) {
        echo "PENDING  {$name}\n";
        continue;
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO messaging_sql_migrations (filename, checksum) VALUES (:f, :c)');
        $stmt->execute(['f' => $name, 'c' => $checksum]);
        // A migration containing DDL (CREATE TABLE and friends) already
        // committed when $sql ran, above — MySQL/InnoDB does that
        // unconditionally — so inTransaction() is false here and calling
        // commit() would throw "There is no active transaction" on a
        // migration that in fact fully applied. Only DML-only migrations
        // still have an open transaction to commit at this point.
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        echo "applied  {$name}\n";
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "FAILED   {$name}\n         {$e->getMessage()}\n");
        exit(1);
    }
}

if ($drift > 0) {
    exit(2);
}

if ($status) {
    echo $pending === 0 ? "\nUp to date.\n" : "\n{$pending} migration(s) pending.\n";
} elseif ($pending === 0) {
    echo "Up to date.\n";
}
