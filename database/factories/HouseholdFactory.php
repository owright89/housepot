<?php

namespace Database\Factories;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Household>
 */
class HouseholdFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->lastName().' Household',
            'currency' => 'GBP',
        ];
    }

    /**
     * Indicate that the household is archived (read-only).
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }

    /**
     * Attach an owner after creating, using the given user or a new one.
     */
    public function withOwner(?User $owner = null): static
    {
        return $this->afterCreating(function (Household $household) use ($owner) {
            $household->allMembers()->attach(
                $owner ?? User::factory()->create(),
                ['role' => HouseholdRole::Owner],
            );
        });
    }

    /**
     * Attach a member after creating, using the given user or a new one. Pass $leftAt for a former member.
     */
    public function withMember(?User $member = null, ?CarbonInterface $leftAt = null): static
    {
        return $this->afterCreating(function (Household $household) use ($member, $leftAt) {
            $household->allMembers()->attach(
                $member ?? User::factory()->create(),
                ['role' => HouseholdRole::Member, 'left_at' => $leftAt],
            );
        });
    }
}
