<x-guest-layout>
    <h1 class="display font-bold text-[20px] mb-3" style="color:var(--ink);">Verify your email</h1>

    <div class="mb-5 text-[13.5px] leading-relaxed" style="color:var(--ink-soft);">
        {{ __('Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-[13px] rounded-lg px-3.5 py-2.5"
             style="background:rgba(43,227,143,0.10);border:1px solid var(--mint);color:var(--mint);">
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-primary-button>
                    {{ __('Resend Verification Email') }}
                </x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="text-[13px] font-medium transition" style="color:var(--ink-soft);"
                    onmouseover="this.style.color='var(--coral)'" onmouseout="this.style.color='var(--ink-soft)'">
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</x-guest-layout>