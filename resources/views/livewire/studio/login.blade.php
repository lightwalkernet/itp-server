<main class="flex min-h-screen items-center justify-center px-6">
    <div class="w-full max-w-sm">
        <h1 class="text-center text-sm font-semibold uppercase tracking-widest text-stone-500">ITP Studio</h1>

        <div class="mt-6 rounded-2xl bg-white p-8 shadow-sm ring-1 ring-stone-200">
            @if (! $codeSent)
                <form wire:submit="sendCode" class="flex flex-col gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label for="email" class="text-sm font-medium">Email</label>
                        <input
                            wire:model="email"
                            id="email"
                            type="email"
                            autocomplete="email"
                            autofocus
                            required
                            class="rounded-lg border border-stone-300 px-3 py-2 focus:border-stone-500 focus:outline-none focus:ring-2 focus:ring-stone-200"
                        >
                        @error('email') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <button type="submit" class="rounded-lg bg-stone-900 px-4 py-2 font-medium text-white hover:bg-stone-700 disabled:opacity-50" wire:loading.attr="disabled">
                        Send sign-in code
                    </button>
                </form>
            @else
                <form wire:submit="verifyCode" class="flex flex-col gap-4">
                    <p class="text-sm text-stone-600">
                        If <span class="font-medium text-stone-900">{{ $email }}</span> has Studio access, a six-digit code is on its way.
                    </p>

                    <div class="flex flex-col gap-1.5">
                        <label for="code" class="text-sm font-medium">Code</label>
                        <input
                            wire:model="code"
                            id="code"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            autofocus
                            required
                            class="rounded-lg border border-stone-300 px-3 py-2 text-center text-lg tracking-[0.5em] focus:border-stone-500 focus:outline-none focus:ring-2 focus:ring-stone-200"
                        >
                        @error('code') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <label class="flex items-start gap-2 text-sm text-stone-600">
                        <input wire:model="keepSignedIn" type="checkbox" class="mt-0.5 rounded border-stone-300">
                        <span>This is my own device. Keep me signed in for 90 days.</span>
                    </label>

                    <button type="submit" class="rounded-lg bg-stone-900 px-4 py-2 font-medium text-white hover:bg-stone-700 disabled:opacity-50" wire:loading.attr="disabled">
                        Sign in
                    </button>

                    <button type="button" wire:click="useDifferentEmail" class="text-sm text-stone-500 hover:text-stone-900">
                        Use a different email
                    </button>
                </form>
            @endif
        </div>
    </div>
</main>
