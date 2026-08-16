<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Order matters: roles before anything that assigns one, plans before
        // any subscription. DemoSeeder is deliberately NOT registered — it
        // generates ~1M rows and is invoked explicitly in local dev only.
        $this->call([
            CountrySeeder::class,
            RoleSeeder::class,
            PlanSeeder::class,
            CategorySeeder::class,
        ]);
    }
}
