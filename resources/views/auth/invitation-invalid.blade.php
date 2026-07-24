<x-guest-layout>
    <div class="text-center">
        <div class="text-3xl mb-3">⏳</div>
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">This invitation link isn’t valid</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
            It may have already been used, or it has expired. Ask an admin to send you a fresh invitation.
        </p>
        <a href="{{ route('login') }}"
           class="inline-block mt-6 text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
            Back to sign in
        </a>
    </div>
</x-guest-layout>
