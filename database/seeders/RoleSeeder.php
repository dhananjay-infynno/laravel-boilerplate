<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Roles, guard `api`.
 *
 * Idempotent by design. The previous version truncated `roles` with foreign key
 * checks disabled, which on any database holding data would silently orphan
 * every `model_has_roles` row — every user would quietly lose their role.
 * `findOrCreate` is safe to run against production.
 */
class RoleSeeder extends Seeder
{
    private const GUARD = 'api';

    public const SUPER_ADMIN = 'super-admin';

    public const ADMIN = 'admin';

    public const SUPPORT = 'support';

    public const USER = 'user';

    public function run(): void
    {
        foreach ([self::SUPER_ADMIN, self::ADMIN, self::SUPPORT, self::USER] as $name) {
            Role::findOrCreate($name, self::GUARD);
        }
    }
}
