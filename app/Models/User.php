<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'is_admin', 'invited_by'])]
#[Hidden(['password', 'remember_token', 'invitation_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** How long an invitation link stays valid. */
    public const INVITE_EXPIRES_DAYS = 14;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'invited_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invited_by');
    }

    /** An invited user who hasn't set a password yet. */
    public function isPending(): bool
    {
        return $this->password === null;
    }

    /** Whether the invitation link is still within its validity window. */
    public function invitationIsValid(): bool
    {
        return $this->invitation_token !== null
            && $this->invited_at !== null
            && $this->invited_at->copy()->addDays(self::INVITE_EXPIRES_DAYS)->isFuture();
    }

    /**
     * Mint a fresh single-use invitation token, store its hash, and return the
     * plaintext to embed in the invite link (the plaintext is never persisted).
     */
    public function issueInvitationToken(): string
    {
        $plain = Str::random(64);

        $this->forceFill([
            'invitation_token' => hash('sha256', $plain),
            'invited_at' => now(),
        ])->save();

        return $plain;
    }

    /** Find a pending invite by its plaintext token. */
    public static function findByInvitationToken(string $plain): ?self
    {
        if ($plain === '') {
            return null;
        }

        return static::query()->where('invitation_token', hash('sha256', $plain))->first();
    }

    /** Accept the invite: set the chosen password, verify the email, burn the token. */
    public function acceptInvitation(string $password): void
    {
        $this->forceFill([
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'invitation_token' => null,
        ])->save();
    }
}
