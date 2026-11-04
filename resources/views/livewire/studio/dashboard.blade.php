<div>
    <header class="border-b border-stone-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
            <span class="text-sm font-semibold uppercase tracking-widest text-stone-500">ITP Studio</span>

            <div class="flex items-center gap-4 text-sm">
                <span class="text-stone-600">{{ $staff->name }} · {{ $staff->role->label() }}</span>

                <form method="POST" action="{{ route('studio.logout') }}">
                    @csrf
                    <button type="submit" class="text-stone-500 hover:text-stone-900">Sign out</button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-6 py-10">
        <h1 class="text-2xl font-semibold">Welcome, {{ $staff->name }}</h1>
        <p class="mt-2 text-stone-600">Studio sections arrive with the pipeline port.</p>

        @if ($canViewHorizon)
            <a href="{{ route('horizon.index') }}" class="mt-8 inline-flex rounded-lg bg-white px-4 py-3 font-medium shadow-sm ring-1 ring-stone-200 hover:ring-stone-400">
                Horizon: queues and failed jobs
            </a>
        @endif
    </main>
</div>
