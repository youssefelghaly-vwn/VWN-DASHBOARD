<?php

namespace App\Team\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * The invitee's side of the flow: a guest opens the emailed link, sees who they
 * are, and sets a password for the first time — which activates the account and
 * logs them in. No authentication is required to reach these routes (the token
 * is the credential), but a valid, unexpired token is.
 */
class InvitationController extends Controller
{
    public function show(string $token)
    {
        $user = User::findByInvitationToken($token);

        if (! $user || ! $user->isPending() || ! $user->invitationIsValid()) {
            return view('auth.invitation-invalid');
        }

        return view('auth.accept-invitation', ['user' => $user, 'token' => $token]);
    }

    public function store(Request $request, string $token)
    {
        $user = User::findByInvitationToken($token);

        if (! $user || ! $user->isPending() || ! $user->invitationIsValid()) {
            return redirect()->route('invitations.show', ['token' => $token]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->name = $data['name'];
        $user->save();
        $user->acceptInvitation($data['password']);

        event(new Registered($user));

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Welcome! Your account is ready.');
    }
}
