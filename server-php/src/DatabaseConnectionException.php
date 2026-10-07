<?php

declare(strict_types=1);

namespace Aicountly\Api;

use PDOException;

/**
 * "There is no usable database connection", with a stable category that names WHY.
 *
 * Still a PDOException, so every caller that already answers 503 database_unavailable to a failed
 * connection keeps doing exactly that. The category is what lets /api/health, the error log and
 * bin/db-check.php say which of several quite different problems this is (see DatabaseDiagnosis).
 * It never carries a key, a password or a host.
 */
final class DatabaseConnectionException extends PDOException
{
    public function __construct(string $message, public readonly string $category)
    {
        parent::__construct($message);
    }
}
