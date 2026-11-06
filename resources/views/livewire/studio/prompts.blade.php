<main class="mx-auto max-w-6xl px-6 py-10">
    <h1 class="text-2xl font-semibold">Prompts</h1>
    <p class="mt-2 max-w-3xl text-stone-600">
        Each stage of the pipeline is one AI call with its own prompt. The method core holds the shared ITP voice and is prepended to the stages that write in it. Saving a prompt creates a new version and makes it active for every new call; older versions stay, so you can always go back.
    </p>

    <div class="mt-8 flex flex-col gap-4">
        @foreach ($stages as $row)
            <a
                wire:key="stage-{{ $row['stage']->value }}"
                href="{{ route('studio.prompts.show', $row['stage']->value) }}"
                @class([
                    'block rounded-lg p-5 shadow-sm ring-1 hover:ring-stone-400',
                    'bg-white ring-stone-200' => $row['stage']->isPipelineStep(),
                    'bg-sky-50 ring-sky-200' => ! $row['stage']->isPipelineStep(),
                ])
            >
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="font-semibold">{{ $row['stage']->label() }}</h2>

                    <span class="text-sm text-stone-500">
                        @if ($row['active'])
                            Active: v{{ $row['active']->version }}
                        @else
                            <span class="font-medium text-red-600">No active version</span>
                        @endif
                        · {{ $row['versionCount'] }} {{ Str::plural('version', $row['versionCount']) }}
                        @if ($row['latest'])
                            · last edited {{ $row['latest']->created_at->diffForHumans() }}
                            @if ($row['latest']->createdBy)
                                by {{ $row['latest']->createdBy->name }}
                            @endif
                        @endif
                    </span>
                </div>

                <dl class="mt-3 grid gap-1 text-sm sm:grid-cols-[6rem_1fr]">
                    @if ($row['stage']->isPipelineStep())
                        <dt class="text-stone-500">Is given</dt>
                        <dd>{{ $row['stage']->reads() }}</dd>
                        <dt class="text-stone-500">Must return</dt>
                        <dd>{{ $row['stage']->purpose() }}</dd>
                        <dt class="text-stone-500">Model</dt>
                        <dd class="font-mono text-xs leading-5">
                            {{ $row['stage']->model() }} · effort {{ $row['stage']->effort() }}
                            @if ($row['stage']->usesMethodCore())
                                <span class="ml-1 font-sans text-sky-700">· method core prepended</span>
                            @endif
                        </dd>
                    @else
                        <dt class="text-stone-500">What it is</dt>
                        <dd>{{ $row['stage']->purpose() }}</dd>
                        <dt class="text-stone-500">Used by</dt>
                        <dd>{{ $row['stage']->reads() }}</dd>
                    @endif
                </dl>
            </a>
        @endforeach
    </div>
</main>
