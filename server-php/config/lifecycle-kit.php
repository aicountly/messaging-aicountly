<?php

declare(strict_types=1);

/**
 * The company-lifecycle worker's Kit (lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php):
 * this API's own database connection (its name and user come from Console, src/ConsoleDatabaseDetails.php)
 * and the hooks that remove attachment files and Drive documents after a purge. See
 * src/Lifecycle/CompanyLifecycle.php and docs/DEPLOYMENT.md.
 */

namespace Aicountly\Api;

require_once __DIR__ . '/../src/Env.php';
require_once __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

return Lifecycle\CompanyLifecycle::build(Db::connect());
