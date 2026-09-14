<?php

declare(strict_types=1);

/**
 * Operational tables: the scheduled work queue, rate limiting, and the audit trail.
 *
 * Decisions embedded here, with their reasons:
 *
 *  - The job queue lives in the database because shared hosting has no worker and no in-memory
 *    store (ADR-0002). `reserved_at`/`reserved_by` make claiming a job an atomic UPDATE, which is
 *    what makes two concurrent cron runs safe; a job that crashes mid-run is reclaimed after its
 *    reservation expires instead of being lost or processed twice forever.
 *  - `attempts` and `available_at` implement retry with backoff without a separate table.
 *  - Rate limiting counts per (bucket, key, window) in the database, because no cache server can be
 *    assumed. `expires_at` allows a scheduled cleanup instead of an unbounded table.
 *  - The audit trail records who did what to which record, and is append-only by contract: no code
 *    path updates or deletes a row here. Sensitive values must never be written into `changes`.
 *
 * Created by: Phase 0, slice 0.2.
 */

use App\Core\Database\Connection;
use App\Core\Database\Schema;
use App\Core\Database\TableDefinition;

return [
    'up' => static function (Schema $schema, Connection $connection): void {
        $schema->create('jobs', static function (TableDefinition $table): void {
            $table->id();
            $table->string('queue', 50)->default('default');
            $table->string('type', 100);            // the job handler name; never a class from input
            $table->text('payload');                // JSON, validated by the handler that consumes it
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(5);
            $table->string('status', 20)->default('queued'); // queued | running | succeeded | failed
            $table->dateTime('available_at');       // earliest time this job may run (backoff)
            $table->dateTime('reserved_at')->nullable();
            $table->string('reserved_by', 100)->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('created_at');
            $table->index(['status', 'available_at']);
            $table->index('queue');
        });

        $schema->create('rate_limits', static function (TableDefinition $table): void {
            $table->string('bucket', 100);          // what is limited: otp_send, login, upload, ...
            $table->string('key_hash', 191);        // hashed subject: ip, mobile, user id
            $table->integer('window_started_at');
            $table->integer('hits')->default(0);
            $table->dateTime('expires_at');
            $table->unique(['bucket', 'key_hash', 'window_started_at']);
            $table->index('expires_at');
        });

        $schema->create('audit_log', static function (TableDefinition $table): void {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->string('action', 100);          // e.g. user.login, order.status_changed
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->text('changes');                // JSON diff; never secrets or full personal data
            $table->string('ip_address', 45)->nullable();
            $table->string('request_id', 32)->nullable();
            $table->dateTime('created_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
            $table->index('created_at');
        });
    },

    'down' => static function (Schema $schema, Connection $connection): void {
        $schema->dropIfExists('audit_log');
        $schema->dropIfExists('rate_limits');
        $schema->dropIfExists('jobs');
    },
];
