<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dir = __DIR__ . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . '/app.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$pdo = $pdo;
        self::migrate($pdo);

        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS accounts (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                supplier_code    TEXT    NOT NULL UNIQUE,
                supplier_name    TEXT    NOT NULL,
                account_name     TEXT    NOT NULL,
                account_no       TEXT    NOT NULL,
                bank_name        TEXT    NOT NULL,
                bank_branch      TEXT,
                account_type     TEXT    NOT NULL DEFAULT 'public',
                status           TEXT    NOT NULL DEFAULT 'draft',
                review_reason    TEXT,
                freeze_reason    TEXT,
                submitted_at     INTEGER,
                reviewed_at      INTEGER,
                frozen_at       INTEGER,
                created_at       INTEGER NOT NULL,
                updated_at       INTEGER NOT NULL
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS state_transitions (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id   INTEGER NOT NULL,
                event        TEXT    NOT NULL,
                from_status  TEXT    NOT NULL,
                to_status    TEXT    NOT NULL,
                operator     TEXT    NOT NULL,
                reason       TEXT,
                meta         TEXT,
                created_at   INTEGER NOT NULL,
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
            );
        ");

        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_transitions_account
            ON state_transitions(account_id, created_at);
        ");
    }
}
