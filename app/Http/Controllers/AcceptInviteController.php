<?php

namespace App\Http\Controllers;

use App\Actions\Households\AcceptInvite;
use App\Exceptions\InvalidInvite;
use App\Models\HouseholdInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AcceptInviteController extends Controller
{
    /**
     * Join the household from a signed invite link.
     *
     * The `signed` middleware rejects tampered links; AcceptInvite handles expiry and reuse,
     * so those get a friendly message instead of a 403.
     */
    public function __invoke(Request $request, HouseholdInvite $invite, AcceptInvite $acceptInvite): RedirectResponse
    {
        try {
            $household = $acceptInvite->handle($request->user(), $invite);
        } catch (InvalidInvite $e) {
            return redirect()->route('households.index')->with('invite_error', $e->getMessage());
        }

        session(['current_household_id' => $household->id]);

        return redirect()
            ->route('households.show', $household)
            ->with('invite_status', __('Welcome to :household!', ['household' => $household->name]));
    }
}
