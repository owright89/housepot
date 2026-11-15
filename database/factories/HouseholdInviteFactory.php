<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HouseholdInvite>
 */
class HouseholdInviteFactory extends Factory
{
    /**
     * Define the model's default state: a usable invite.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'invited_by' => User::factory(),
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Indicate that the invite has been used.
     */
    public function accepted(?User $acceptor = null): static
    {
        return $this->state(fn (array $attributes) => [
            'accepted_at' => now(),
            'accepted_by' => $acceptor ?? User::factory(),
        ]);
    }

    /**
     * Indicate that the owner cancelled the invite.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }

    /**
     * Indicate that the invite is past its expiry.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
