<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class BranchFactory extends Factory
{
    /**
     * ربط الفاكتوري بموديل Branch
     */
    protected $model = Branch::class;

    /**
     * تعريف البيانات الوهمية
     */
    public function definition(): array
    {
        return [
            'name'    => $this->faker->company . ' Hotel',
            'address' => $this->faker->address,
        ];
    }
}