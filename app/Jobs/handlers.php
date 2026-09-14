<?php

declare(strict_types=1);

use App\Core\Database\Connection;
use App\Core\Logger;
use App\Core\Security\RateLimiter;

/**
 * The job handler registry.
 *
 * A job type is a name in this map. Registering handlers explicitly (rather than by class-name
 * convention) means a queued payload can never choose which code runs: the queue stores a type from
 * this list, and an unknown type fails loudly instead of being interpreted (backend rule, section 8).
 *
 * Every handler must be idempotent: the runner may run it again after a crash or a timeout, and cron
 * may overlap. Each handler therefore states in one line why running it twice is harmless.
 *
 * Signature: function (array $payload, Connection $database, Logger $logger): void
 */
return [
    /**
     * Removes expired rate-limit windows. Harmless twice: deleting already-absent rows is a no-op.
     */
    'maintenance.purge_rate_limits' => static function (array $payload, Connection $database, Logger $logger): void {
        $deleted = (new RateLimiter($database))->purgeExpired();
        if ($deleted > 0) {
            $logger->info('rate_limits_purged', ['deleted' => $deleted]);
        }
    },
];
