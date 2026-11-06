<main class="mx-auto max-w-6xl px-6 py-10">
    <a href="{{ route('studio.prompts.index') }}" class="text-sm text-stone-500 hover:text-stone-900">← All prompts</a>

    <h1 class="mt-2 text-2xl font-semibold">{{ $stage->label() }} prompt</h1>

    @if ($stage->isPipelineStep())
        <dl class="mt-3 grid max-w-4xl gap-1 text-sm sm:grid-cols-[6rem_1fr]">
            <dt class="text-stone-500">Is given</dt>
            <dd>{{ $stage->reads() }}</dd>
            <dt class="text-stone-500">Must return</dt>
            <dd>{{ $stage->purpose() }}</dd>
            <dt class="text-stone-500">Model</dt>
            <dd class="font-mono text-xs leading-5">{{ $stage->model() }} · effort {{ $stage->effort() }} · up to {{ number_format($stage->maxTokens()) }} output tokens</dd>
        </dl>

        @if ($stage->usesMethodCore())
            <p class="mt-4 max-w-4xl rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-900 ring-1 ring-sky-200">
                The <a href="{{ route('studio.prompts.show', \App\Enums\ItpStage::MethodCore->value) }}" class="font-medium underline">method core</a>
                @if ($activeMethodCore)
                    (v{{ $activeMethodCore->version }})
                @else
                    <span class="font-medium text-red-700">(no active version: this stage cannot run)</span>
                @endif
                is prepended to this prompt as its own cached system block. Edit the shared voice rules there, not here.
            </p>
        @endif
    @else
        <p class="mt-3 max-w-4xl text-sm text-stone-600">{{ $stage->purpose() }} It is not called on its own, so it has no model of its own.</p>
        <p class="mt-2 max-w-4xl text-sm">
            <span class="text-stone-500">Prepended to:</span>
            @foreach ($methodCoreStages as $usingStage)
                <a href="{{ route('studio.prompts.show', $usingStage->value) }}" class="font-medium hover:underline">{{ $usingStage->label() }}</a>@unless ($loop->last), @endunless
            @endforeach
        </p>
    @endif

    @if (session('status'))
        <p class="mt-6 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-emerald-200">{{ session('status') }}</p>
    @endif

    <form wire:submit="saveVersion" class="mt-8 flex flex-col gap-4">
        <div class="flex flex-col gap-1.5">
            <label for="body" class="text-sm font-medium">
                @if ($stage->isPipelineStep())
                    Active prompt (the system prompt for every {{ Str::lower($stage->label()) }} call)
                @else
                    Active method core (the first system block of every call it is prepended to)
                @endif
            </label>
            <textarea
                wire:model="body"
                id="body"
                rows="28"
                spellcheck="false"
                class="w-full rounded-lg border border-stone-300 bg-white px-4 py-3 font-mono text-sm leading-6 focus:border-stone-500 focus:outline-none focus:ring-2 focus:ring-stone-200"
            ></textarea>
            @error('body') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-col gap-1.5">
            <label for="notes" class="text-sm font-medium">What changed, and why (optional)</label>
            <input
                wire:model="notes"
                id="notes"
                type="text"
                class="rounded-lg border border-stone-300 px-3 py-2 focus:border-stone-500 focus:outline-none focus:ring-2 focus:ring-stone-200"
            >
            @error('notes') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <button type="submit" class="rounded-lg bg-stone-900 px-4 py-2 font-medium text-white hover:bg-stone-700 disabled:opacity-50" wire:loading.attr="disabled">
                Save as new version
            </button>
            <span class="ml-3 text-sm text-stone-500">Saving never changes an old version: it adds v{{ ($versions->first()?->version ?? 0) + 1 }} and makes it active.</span>
        </div>
    </form>

    @if ($viewing)
        <section class="mt-10 rounded-lg bg-white p-5 shadow-sm ring-1 ring-stone-200">
            <div class="flex items-center justify-between gap-4">
                <h2 class="font-semibold">v{{ $viewing->version }} @if ($viewing->isActive()) (active) @endif</h2>
                <button type="button" wire:click="closeViewer" class="text-sm text-stone-500 hover:text-stone-900">Close</button>
            </div>
            <pre class="mt-3 max-h-[32rem] overflow-auto whitespace-pre-wrap rounded bg-stone-50 p-4 font-mono text-sm leading-6">{{ $viewing->body }}</pre>
        </section>
    @endif

    <section class="mt-10">
        <h2 class="text-lg font-semibold">Version history</h2>

        <div class="mt-3 overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-stone-200">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-stone-200 text-stone-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Version</th>
                        <th class="px-4 py-2 font-medium">Saved by</th>
                        <th class="px-4 py-2 font-medium">Saved</th>
                        <th class="px-4 py-2 font-medium">Notes</th>
                        <th class="px-4 py-2 text-right font-medium">Generations</th>
                        <th class="px-4 py-2 text-right font-medium">Calls</th>
                        <th class="px-4 py-2 text-right font-medium">Avg cost per call</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($versions as $version)
                        <tr wire:key="version-{{ $version->id }}">
                            <td class="px-4 py-2 font-medium">
                                v{{ $version->version }}
                                @if ($version->isActive())
                                    <span class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Active</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">{{ $version->createdBy?->name ?? '—' }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ $version->created_at->format('j M Y, H:i') }}</td>
                            <td class="px-4 py-2 text-stone-600">{{ $version->notes }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $version->generations_count }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $version->calls_count }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ $version->average_cost === null ? '—' : '$'.number_format((float) $version->average_cost, 4) }}
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <button type="button" wire:click="view({{ $version->id }})" class="text-stone-600 hover:text-stone-900">View</button>
                                @unless ($version->isActive())
                                    <button
                                        type="button"
                                        wire:click="activate({{ $version->id }})"
                                        wire:confirm="Make v{{ $version->version }} the active version? Unsaved edits in the editor will be replaced."
                                        class="ml-3 font-medium text-stone-900 hover:underline"
                                    >Make active</button>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</main>
