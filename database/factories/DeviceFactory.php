<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Device> */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'category' => fake()->randomElement(['Notebook', 'Celular', 'Tablet', 'Console']),
            'brand' => fake()->randomElement(['Dell', 'Samsung', 'Apple', 'Lenovo']),
            'model' => fake()->bothify('??-####'),
            'serial_number' => fake()->bothify('SN#########'),
            'active' => true,
        ];
    }
}
