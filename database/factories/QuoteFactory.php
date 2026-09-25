<?php

namespace Database\Factories;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Quote> */
class QuoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'version' => 1,
            'status' => QuoteStatus::Draft,
            'total_cents' => 0,
            'created_by' => User::factory(),
        ];
    }

    public function status(QuoteStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
