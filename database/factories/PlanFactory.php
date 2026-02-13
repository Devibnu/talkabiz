<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PlanFactory
 * 
 * Factory untuk membuat instance Plan dalam testing.
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = $this->faker->unique()->slug(2);
        
        return [
            'code' => $code,
            'name' => ucfirst($this->faker->words(2, true)),
            'description' => $this->faker->sentence(),
            'segment' => Plan::SEGMENT_UMKM,
            'price' => $this->faker->randomElement([99000, 199000, 299000, 499000]),
            'currency' => 'IDR',
            'discount_price' => null,
            'duration_days' => 30,
            'quota_messages' => $this->faker->numberBetween(1000, 10000),
            'quota_contacts' => $this->faker->numberBetween(500, 5000),
            'quota_campaigns' => $this->faker->numberBetween(3, 20),
            'limit_messages_monthly' => $this->faker->numberBetween(1000, 10000),
            'limit_messages_daily' => $this->faker->numberBetween(50, 500),
            'limit_messages_hourly' => $this->faker->numberBetween(20, 100),
            'limit_wa_numbers' => $this->faker->numberBetween(1, 5),
            'limit_active_campaigns' => $this->faker->numberBetween(3, 20),
            'limit_recipients_per_campaign' => $this->faker->numberBetween(200, 2000),
            'features' => ['inbox', 'campaign', 'template'],
            'is_purchasable' => true,
            'is_visible' => true,
            'is_active' => true,
            'is_recommended' => false,
            'is_self_serve' => true,
            'is_enterprise' => false,
            'is_popular' => false,
            'target_margin' => $this->faker->randomFloat(2, 10, 40),
            'sort_order' => $this->faker->numberBetween(0, 10),
            'badge_text' => null,
            'badge_color' => null,
        ];
    }

    /**
     * Plan aktif
     */
    public function active(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Plan tidak aktif
     */
    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Plan self-serve (untuk landing page)
     */
    public function selfServe(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_self_serve' => true,
            'is_enterprise' => false,
            'is_purchasable' => true,
        ]);
    }

    /**
     * Plan enterprise
     */
    public function enterprise(): static
    {
        return $this->state(fn(array $attributes) => [
            'segment' => Plan::SEGMENT_CORPORATE,
            'is_self_serve' => false,
            'is_enterprise' => true,
            'is_purchasable' => false,
            'limit_messages_monthly' => null,
            'limit_wa_numbers' => null,
        ]);
    }

    /**
     * Plan popular
     */
    public function popular(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_popular' => true,
            'badge_text' => 'Paling Populer',
            'badge_color' => 'warning',
        ]);
    }

    /**
     * Plan gratis
     */
    public function free(): static
    {
        return $this->state(fn(array $attributes) => [
            'price' => 0,
            'is_purchasable' => false,
            'badge_text' => 'Free',
            'badge_color' => 'success',
        ]);
    }

    /**
     * Plan dengan diskon
     */
    public function withDiscount(float $discountPrice): static
    {
        return $this->state(fn(array $attributes) => [
            'discount_price' => $discountPrice,
        ]);
    }

    /**
     * Plan unlimited
     */
    public function unlimited(): static
    {
        return $this->state(fn(array $attributes) => [
            'limit_messages_monthly' => null,
            'limit_messages_daily' => null,
            'limit_messages_hourly' => null,
            'limit_wa_numbers' => null,
            'limit_active_campaigns' => null,
            'limit_recipients_per_campaign' => null,
        ]);
    }
}
