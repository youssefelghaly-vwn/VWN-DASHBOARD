<x-guest-layout>
    <h1 class="display font-bold text-[20px] mb-6" style="color:var(--ink);">Log in</h1>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded" name="remember"
                    style="accent-color:var(--mint-deep);border-color:var(--line);">
                <span class="ms-2 text-[13px]" style="color:var(--ink-soft);">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-5">
            @if (Route::has('password.request'))
                <a class="text-[13px] font-medium rounded-md transition" style="color:var(--ink-soft);"
                   onmouseover="this.style.color='var(--mint)'" onmouseout="this.style.color='var(--ink-soft)'"
                   href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="ms-4">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>