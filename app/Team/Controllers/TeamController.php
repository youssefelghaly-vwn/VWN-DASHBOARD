<?php

namespace App\Team\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Team\Mail\UserInvitationMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Admin team management: list members and invite new ones by email. An invite
 * creates a password-less user and emails them a single-use link to set their
 * own password (see InvitationController for the accept side).
 */
class TeamController extends Controller
{
    public function index()
    {
        return view('admin.team', [
            'users' => User::orderByDesc('is_admin')->orderBy('name')->orderBy('email')->get(),
        ]);
    }

    public function invite(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'is_admin' => ['nullable', 'boolean'],
        ]);

        $existing = User::whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->first();

        // An already-active account can't be re-invited; a still-pending one just
        // gets a fresh link (same as "resend").
        if ($existing && ! $existing->isPending()) {
            return back()->withErrors(['email' => 'That email already belongs to an active account.'])->withInput();
        }

        $user = $existing ?: new User;
        $user->fill([
            'email' => $data['email'],
            'name' => ($data['name'] ?? null) ?: $user->name ?: explode('@', $data['email'])[0],
            'is_admin' => (bool) ($data['is_admin'] ?? false),
            'invited_by' => $request->user()->id,
        ]);
        $user->password = null;
        $user->save();

        $this->sendInvite($user, $request->user()->name);

        return back()->with('status', "Invitation sent to {$user->email}.");
    }

    public function resend(Request $request, User $user)
    {
        if (! $user->isPending()) {
            return back()->withErrors(['email' => 'That account is already active.']);
        }

        $this->sendInvite($user, $request->user()->name);

        return back()->with('status', "Invitation re-sent to {$user->email}.");
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['email' => 'You can’t remove your own account.']);
        }

        $user->delete();

        return back()->with('status', 'Member removed.');
    }

    private function sendInvite(User $user, ?string $inviterName): void
    {
        $token = $user->issueInvitationToken();
        $url = route('invitations.show', ['token' => $token]);

        Mail::to($user->email)->send(new UserInvitationMail($user, $url, $inviterName));
    }
}
