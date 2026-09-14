<?php

declare(strict_types=1);

/**
 * Core identity tables.
 *
 * Decisions embedded here, with their reasons:
 *
 *  - `users.mobile` is the login identity: the owner decided on mobile + one-time code with no
 *    passwords (O-6), so there is no password column anywhere and no password reset flow to design.
 *    It is stored in the canonical 09xxxxxxxxx form, which is what makes "one user per number"
 *    actually hold.
 *  - `mobile_normalized` naming is avoided deliberately: the canonical value IS the stored value,
 *    because a second copy of the same fact is a second thing that can drift.
 *  - `role` is a short string with an explicit allowed set enforced in code, not an enum column:
 *    adding a role must not require a schema change on a live installation.
 *  - timestamps are stored in UTC as DATETIME (MySQL) / TEXT (SQLite) and written by the
 *    application clock, so the same instant is comparable across engines and hosts.
 *  - `users.owner_type` exists for C-14 (SaaS + B2B on one codebase): an account is either a
 *    personal account or belongs to an organization. Building this in now costs one column; adding
 *    it later would mean rewriting every authorization query in the product. Organization tables
 *    themselves arrive in Phase 6 - the column alone does not implement B2B.
 *
 * Created by: Phase 0, slice 0.2.
 */

use App\Core\Database\Connection;
use App\Core\Database\Schema;
use App\Core\Database\TableDefinition;

return [
    'up' => static function (Schema $schema, Connection $connection): void {
        $schema->create('users', static function (TableDefinition $table): void {
            $table->id();
            $table->string('mobile', 20)->unique();
            $table->string('display_name', 100)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('role', 20)->default('user');           // user | admin (enforced in code)
            $table->string('owner_type', 20)->default('personal'); // personal | organization (C-14)
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('last_login_at')->nullable();
            $table->index('role');
            $table->index('owner_type');
        });

        $schema->create('sessions', static function (TableDefinition $table): void {
            $table->id();
            // The session identifier is stored hashed: a leaked database dump must not hand over
            // live sessions. The cookie carries the secret, the database stores its hash.
            $table->string('token_hash', 191)->unique();
            $table->integer('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();          // long enough for IPv6
            $table->string('user_agent', 191)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('expires_at');
            $table->foreign('user_id', 'id', 'users', 'cascade');
            $table->index('expires_at');
        });

        $schema->create('settings', static function (TableDefinition $table): void {
            $table->string('key_name', 191);
            $table->text('value');
            $table->dateTime('updated_at');
            $table->unique('key_name');
        });
    },

    'down' => static function (Schema $schema, Connection $connection): void {
        // Reverse creation order so foreign keys never block the drop.
        $schema->dropIfExists('settings');
        $schema->dropIfExists('sessions');
        $schema->dropIfExists('users');
    },
];
