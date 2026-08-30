<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Business;
use Illuminate\Support\Str;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {

        Business::insert([

            [
                'id' => Str::uuid(),

                'name' => 'ABC Trading Company',

                'registration_number' => 'REG-1001',
            ],


            [
                'id' => Str::uuid(),

                'name' => 'Cairo Financial Services',

                'registration_number' => 'REG-1002',
            ],


            [
                'id' => Str::uuid(),

                'name' => 'Nile Technology Solutions',

                'registration_number' => 'REG-1003',
            ],


            [
                'id' => Str::uuid(),

                'name' => 'Delta Manufacturing Ltd',

                'registration_number' => 'REG-1004',
            ],


            [
                'id' => Str::uuid(),

                'name' => 'Smart Vision Group',

                'registration_number' => 'REG-1005',
            ],


        ]);

    }
}
