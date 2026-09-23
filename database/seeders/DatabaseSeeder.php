<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds reference data for every environment. Local demo accounts live in
     * DevelopmentSeeder (`php artisan db:seed --class=DevelopmentSeeder`).
     */
    public function run(): void
    {
        $this->call(PermissionCatalogSeeder::class);
    }
}
