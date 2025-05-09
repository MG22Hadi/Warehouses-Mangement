<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('departments')->insert([
            ['name' => 'IT',          'created_at' => now(), 'updated_at' => now()],
            ['name' => 'HR',          'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Warehouse',   'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Finance',     'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
