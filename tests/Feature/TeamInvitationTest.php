<?php

namespace Tests\Feature;

use App\Models\User;
use App\Team\Mail\UserInvitationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_admin_can_invite_a_new_member_and_an_email_is_sent(): void
    {
        Mail::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/team/invite', [
            'email' => 'new@company.com', 'name' => 'New Person', 'is_admin' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $user = User::where('email', 'new@company.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isPending());        // no password yet
        $this->assertTrue($user->is_admin);
        $this->assertNotNull($user->invitation_token); // hashed token stored
        $this->assertSame($admin->id, $user->invited_by);

        Mail::assertSent(UserInvitationMail::class, function ($mail) {
            return $mail->hasTo('new@company.com') && str_contains($mail->acceptUrl, '/invite/');
        });
    }

    public function test_inviting_an_active_email_is_rejected(): void
    {
        Mail::fake();
        $admin = $this->admin();
        User::factory()->create(['email' => 'taken@company.com']); // has a password → active

        $this->actingAs($admin)->post('/team/invite', ['email' => 'taken@company.com'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_inviting_a_still_pending_email_resends_the_link(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $pending = User::create(['name' => 'Pending', 'email' => 'p@company.com', 'password' => null]);

        $this->actingAs($admin)->post('/team/invite', ['email' => 'p@company.com'])
            ->assertSessionHas('status');

        Mail::assertSent(UserInvitationMail::class);
        $this->assertDatabaseCount('users', 2); // no duplicate created
    }

    public function test_non_admins_cannot_reach_team_or_invite(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/team')->assertForbidden();
        $this->actingAs($user)->post('/team/invite', ['email' => 'x@y.com'])->assertForbidden();
    }

    public function test_admin_cannot_remove_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->delete("/team/{$admin->id}")->assertSessionHasErrors('email');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
