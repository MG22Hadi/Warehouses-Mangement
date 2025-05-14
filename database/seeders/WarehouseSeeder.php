<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
//        $warehouses=[
//            ['name'=>'m22','location'=>'l22'],
//            ['name'=>'m2','location'=>'l2']
//        ];
//        foreach ($warehouses as $warehouse){
//            Warehouse::create($warehouse);
//        }

        $numberOfWarehouses = 10;
        $location = 'دمشق';

        for ($i = 0; $i < $numberOfWarehouses; $i++) {
            Warehouse::create([
                'name' => 'مستودع ' . ($i + 1),
                'location' => $location,
            ]);

        }
    }
}
