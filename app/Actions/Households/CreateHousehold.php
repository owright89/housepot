<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateHousehold
{
    public function handle(User $owner, string $name, string $currency): Household
    {
        return DB::transaction(function () use ($owner, $name, $currency) {
            $household = Household::create([
                'name' => $name,
                'currency' => $currency,
            ]);

            $household->members()->attach($owner, ['role' => HouseholdRole::Owner]);

            return $household;
        });
    }
}
