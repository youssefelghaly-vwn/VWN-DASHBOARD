<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AcceptInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function pendingUser(): array
    {
        $user = User::create(['name' => 'Invitee', 'email' => 'invitee@company.com', 'password' => null]);
        $token = $user->issueInvitationToken();

        return [$user, $token];
    }

    public function test_the_accept_page_loads_for_a_valid_token(): void
    {
        [$user, $token] = $this->pendingUser();

        $this->get("/invite/{$token}")
            ->assertOk()
            ->assertViewIs('auth.accept-invitation')
            ->assertSee('invitee@company.com');
    }

    public function test_an_unknown_token_shows_the_invalid_page(): void
    {
        $this->get('/invite/not-a-real-token')
            ->assertOk()
            ->assertViewIs('auth.invitation-invalid');
    }

    public function test_an_expired_token_is_rejected(): void
    {
        [$user, $token] = $this->pendingUser();
        $user->forceFill(['invited_at' => now()->subDays(User::INVITE_EXPIRES_DAYS + 1)])->save();

        $this->get("/invite/{$token}")->assertViewIs('auth.invitation-invalid');
    }

    public function test_accepting_sets_the_password_activates_and_logs_in(): void
    {
        [$user, $token] = $this->pendingUser();

        $response = $this->post("/invite/{$token}", [
            'name' => 'Invitee Renamed',
            'password' => 'super-secret-123',
            'password_confirmation' => 'super-secret-123',
        ]);

        $response->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertSame('Invitee Renamed', $user->name);
        $this->assertNull($user->invitation_token);          // token burned (single-use)
        $this->assertFalse($user->isPending());              // now has a password
        $this->assertNotNull($user->email_verified_at);      // email verified by accepting
        $this->assertTrue(Hash::check('super-secret-123', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_must_be_confirmed(): void
    {
        [$user, $token] = $this->pendingUser();

        $this->post("/invite/{$token}", [
            'name' => 'X',
            'password' => 'super-secret-123',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->isPending());
        $this->assertGuest();
    }

    public function test_a_used_token_cannot_be_reused(): void
    {
        [$user, $token] = $this->pendingUser();

        $this->post("/invite/{$token}", [
            'name' => 'Invitee', 'password' => 'super-secret-123', 'password_confirmation' => 'super-secret-123',
        ])->assertRedirect(route('dashboard'));

        // Log back out and try the same link again.
        auth()->logout();
        $this->get("/invite/{$token}")->assertViewIs('auth.invitation-invalid');
    }
}
