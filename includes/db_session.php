<?php
// Keeps login sessions in the database instead of in files.
// Used on Vercel, where each request can run on a fresh server that has
// never seen the session file saved by the previous request.
// The php_sessions table is created automatically the first time it's needed.

class DbSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        db()->exec("
            CREATE TABLE IF NOT EXISTS php_sessions (
                id         VARCHAR(128) NOT NULL PRIMARY KEY,
                data       MEDIUMTEXT   NOT NULL,
                updated_at INT UNSIGNED NOT NULL,
                INDEX (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = db()->prepare('SELECT data FROM php_sessions WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }

    public function write(string $id, string $data): bool
    {
        $stmt = db()->prepare('
            INSERT INTO php_sessions (id, data, updated_at) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)
        ');
        return $stmt->execute([$id, $data, time()]);
    }

    public function destroy(string $id): bool
    {
        return db()->prepare('DELETE FROM php_sessions WHERE id = ?')->execute([$id]);
    }

    // Removes sessions nobody has used for a while
    public function gc(int $max_lifetime): int|false
    {
        $stmt = db()->prepare('DELETE FROM php_sessions WHERE updated_at < ?');
        $stmt->execute([time() - max($max_lifetime, SESSION_TIMEOUT)]);
        return $stmt->rowCount();
    }
}
