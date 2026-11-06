<main class="mx-auto max-w-6xl px-6 py-10">
    <h1 class="text-2xl font-semibold">Welcome, {{ $staff->name }}</h1>
    <p class="mt-2 text-stone-600">Inspect what the pipeline wrote, tune the prompts, and watch what each ITP costs.</p>

    <div class="mt-8 grid gap-4 sm:grid-cols-2">
        @can('managePrompts')
            <a href="{{ route('studio.prompts.index') }}" class="rounded-lg bg-white px-4 py-3 shadow-sm ring-1 ring-stone-200 hover:ring-stone-400">
                <span class="font-medium">Prompts</span>
                <span class="block text-sm text-stone-500">Edit each stage's prompt; every save is a new version.</span>
            </a>
        @endcan

        <a href="{{ route('studio.generations.index') }}" class="rounded-lg bg-white px-4 py-3 shadow-sm ring-1 ring-stone-200 hover:ring-stone-400">
            <span class="font-medium">Generations</span>
            <span class="block text-sm text-stone-500">Every run of the pipeline, with its answers, output and AI calls.</span>
        </a>

        @can('viewCosts')
            <a href="{{ route('studio.costs') }}" class="rounded-lg bg-white px-4 py-3 shadow-sm ring-1 ring-stone-200 hover:ring-stone-400">
                <span class="font-medium">Costs</span>
                <span class="block text-sm text-stone-500">What the AI calls cost, per stage, model, user and ITP.</span>
            </a>
        @endcan

        @if ($canViewHorizon)
            <a href="{{ route('horizon.index') }}" class="rounded-lg bg-white px-4 py-3 shadow-sm ring-1 ring-stone-200 hover:ring-stone-400">
                <span class="font-medium">Horizon</span>
                <span class="block text-sm text-stone-500">Horizon: queues and failed jobs</span>
            </a>
        @endif
    </div>
</main>
