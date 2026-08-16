<?php

namespace Tests;

use Database\Seeders\CategorySeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Client;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed roles.
     *
     * RefreshDatabase truncates everything including `roles`, so any test that
     * registers a user through the API dies inside AuthService::register() on
     * assignRole() with "There is no role named `user` for guard `api`".
     *
     * Opt-in rather than a global setUp: unit tests that never touch the
     * database should not pay for a seeder run.
     */
    protected function seedRoles(): void
    {
        $this->seed(RoleSeeder::class);
    }

    /** Plans and categories — needed by anything that starts a trial. */
    protected function seedPlans(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(CategorySeeder::class);
    }

    /**
     * Make Passport able to issue tokens.
     *
     * TWO separate things are required, and they fail differently:
     *
     * 1. SIGNING KEYS ON DISK. Without storage/oauth-private.key every
     *    createToken() dies with "LogicException: Invalid key supplied". Keys
     *    live on the filesystem, so this survives RefreshDatabase and only has
     *    to happen once — but generating it here means a fresh clone and CI
     *    both work with no manual step.
     *
     * 2. A PERSONAL ACCESS CLIENT ROW. createToken() resolves a client with the
     *    `personal_access` grant out of `oauth_clients`, and RefreshDatabase
     *    truncates that table before EVERY test. Unlike the keys, this must be
     *    recreated each time.
     *
     * Call from setUp() in any test that logs in or issues a token.
     */
    protected function setUpPassport(): void
    {
        if (! file_exists(storage_path('oauth-private.key'))) {
            Artisan::call('passport:keys', ['--force' => true, '--no-interaction' => true]);
        }

        Client::factory()
            ->asPersonalAccessTokenClient()
            ->create([
                'name' => 'Test Personal Access Client',
                // Must match config('auth.guards.api.provider') or
                // ClientRepository::personalAccessClient() will not match it,
                // and the failure looks identical to a missing client.
                'provider' => 'users',
            ]);
    }
}
