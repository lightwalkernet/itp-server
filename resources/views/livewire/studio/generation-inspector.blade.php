<main class="mx-auto max-w-6xl px-6 py-10">
    <a href="{{ route('studio.generations.index') }}" class="text-sm text-stone-500 hover:text-stone-900">← All generations</a>

    <div class="mt-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Generation {{ $generation->created_at->format('j M Y, H:i') }}</h1>
        <x-studio.status :status="$generation->status" />
    </div>

    <p class="mt-1 text-sm text-stone-500">
        {{ $generation->user?->email ?? 'Deleted user' }}
        · <span class="font-mono">{{ $generation->id }}</span>
        · {{ $generation->followup_rounds }} follow-up {{ Str::plural('round', $generation->followup_rounds) }}
        · total cost ${{ number_format($totalCost, 4) }}
        @if ($generation->completed_at)
            · completed {{ $generation->completed_at->format('j M Y, H:i') }}
        @endif
    </p>

    @if ($generation->last_error)
        <section class="mt-6 rounded-lg bg-red-50 p-4 ring-1 ring-red-200">
            <h2 class="text-sm font-semibold text-red-800">Last error</h2>
            <pre class="mt-2 whitespace-pre-wrap font-mono text-xs text-red-900">{{ $generation->last_error }}</pre>
        </section>
    @endif

    @if ($generation->safety_route)
        <p class="mt-6 text-sm">
            <span class="font-medium">Safety:</span> {{ $generation->safety_route->value }}
            @if (filled($generation->safety_categories))
                ({{ implode(', ', $generation->safety_categories) }})
            @endif
            @if ($generation->safety_confidence !== null)
                · confidence {{ number_format($generation->safety_confidence, 2) }}
            @endif
            @if (filled($generation->safety_reason))
                · <span class="text-stone-600">{{ $generation->safety_reason }}</span>
            @endif
        </p>
    @endif

    <section class="mt-8">
        <h2 class="text-lg font-semibold">Intake answers</h2>
        <dl class="mt-3 grid gap-x-6 gap-y-3 rounded-lg bg-white p-5 text-sm shadow-sm ring-1 ring-stone-200 sm:grid-cols-[10rem_1fr]">
            @foreach ($intakeAnswers as $label => $answer)
                <dt class="text-stone-500">{{ $label }}</dt>
                <dd class="whitespace-pre-wrap">{{ filled($answer) ? $answer : '—' }}</dd>
            @endforeach
        </dl>
    </section>

    <section class="mt-8">
        <h2 class="text-lg font-semibold">Follow-up questions</h2>
        @forelse ($followupRounds as $round => $followups)
            <div wire:key="round-{{ $round }}" class="mt-3 rounded-lg bg-white p-5 shadow-sm ring-1 ring-stone-200">
                <h3 class="text-sm font-semibold text-stone-500">Round {{ $round }}</h3>
                <dl class="mt-2 flex flex-col gap-3 text-sm">
                    @foreach ($followups as $followup)
                        <div wire:key="followup-{{ $followup->id }}">
                            <dt class="font-medium">
                                Q: {{ $followup->question_text }}
                                @if (filled($followup->gap_slot))
                                    <span class="ml-1 rounded-full bg-stone-100 px-2 py-0.5 font-mono text-xs font-normal text-stone-600">{{ $followup->gap_slot }}</span>
                                @endif
                            </dt>
                            <dd class="mt-1 whitespace-pre-wrap text-stone-700">
                                A:
                                @if ($followup->answered_at === null)
                                    <span class="text-stone-400">(not answered yet)</span>
                                @elseif (blank($followup->answer_text))
                                    <span class="text-stone-400">(skipped)</span>
                                @else
                                    {{ $followup->answer_text }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @empty
            <p class="mt-2 text-sm text-stone-500">No follow-up questions were asked.</p>
        @endforelse
    </section>

    <section class="mt-8">
        <h2 class="text-lg font-semibold">What the stages derived</h2>
        <div class="mt-3 flex flex-col gap-3">
            <details class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-stone-200">
                <summary class="cursor-pointer text-sm font-medium">Extraction (slots, gaps, sufficient)</summary>
                <pre class="mt-3 overflow-auto whitespace-pre-wrap font-mono text-xs">{{ $generation->extraction === null ? 'Not run yet.' : json_encode($generation->extraction, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
            <details class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-stone-200">
                <summary class="cursor-pointer text-sm font-medium">Derived slots (reversal)</summary>
                <pre class="mt-3 overflow-auto whitespace-pre-wrap font-mono text-xs">{{ $generation->derived_slots === null ? 'Not run yet.' : json_encode($generation->derived_slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
            <details class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-stone-200">
                <summary class="cursor-pointer text-sm font-medium">Corrections ({{ count($generation->corrections ?? []) }}, from flags; passed to regeneration, Breathe 11, Quantum Whys and title)</summary>
                <pre class="mt-3 overflow-auto whitespace-pre-wrap font-mono text-xs">{{ blank($generation->corrections) ? 'None yet.' : json_encode($generation->corrections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        </div>
    </section>

    <section class="mt-8">
        <h2 class="text-lg font-semibold">
            Attunement
            @if ($generation->attunement)
                <span class="text-sm font-normal text-stone-500">version {{ $generation->attunement->version }}@if ($generation->attunement->settled_at), settled {{ $generation->attunement->settled_at->format('j M Y, H:i') }}@endif</span>
            @endif
        </h2>

        @if ($generation->attunement)
            <ol class="mt-3 flex flex-col gap-2">
                @foreach ($generation->attunement->segments as $segment)
                    <li
                        wire:key="segment-{{ $segment->id }}"
                        @class([
                            'rounded-lg p-4 text-sm ring-1',
                            'bg-white shadow-sm ring-stone-200' => ! $segment->isSuperseded(),
                            'bg-stone-100 text-stone-500 ring-stone-200' => $segment->isSuperseded(),
                        ])
                    >
                        <div class="flex flex-wrap items-center gap-2 text-xs text-stone-500">
                            <span class="font-mono">#{{ $segment->order }} · v{{ $segment->version }}</span>
                            @if ($segment->isSuperseded())
                                <span class="rounded-full bg-stone-200 px-2 py-0.5 font-medium text-stone-700">Superseded {{ $segment->superseded_at->format('j M, H:i') }}</span>
                            @endif
                        </div>
                        <p @class(['mt-1 whitespace-pre-wrap', 'line-through decoration-stone-400' => $segment->isSuperseded()])>{{ $segment->text }}</p>

                        @foreach ($segment->flags as $flag)
                            <div wire:key="flag-{{ $flag->id }}" class="mt-3 rounded border-l-4 border-amber-400 bg-amber-50 px-3 py-2 text-stone-800">
                                <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">Flagged · {{ $flag->status->value }}</p>
                                <p class="mt-1"><span class="font-medium">Why:</span> {{ $flag->user_explanation }}</p>
                                @if (filled($flag->requested_change))
                                    <p class="mt-1"><span class="font-medium">Requested change:</span> {{ $flag->requested_change }}</p>
                                @endif
                            </div>
                        @endforeach
                    </li>
                @endforeach
            </ol>
        @else
            <p class="mt-2 text-sm text-stone-500">No attunement yet.</p>
        @endif
    </section>

    <section class="mt-8">
        <h2 class="text-lg font-semibold">Companions and title</h2>
        <div class="mt-3 grid gap-4 md:grid-cols-2">
            @forelse ($generation->companions as $companion)
                <div wire:key="companion-{{ $companion->id }}" class="rounded-lg bg-white p-5 text-sm shadow-sm ring-1 ring-stone-200">
                    <h3 class="font-semibold">{{ $companion->type->value }} <span class="font-normal text-stone-500">from attunement v{{ $companion->from_attunement_version }}</span></h3>
                    <ol class="mt-2 list-decimal space-y-1 pl-5">
                        @foreach ($companion->content as $item)
                            <li>{{ is_array($item) ? ($item['text'] ?? '') : $item }}</li>
                        @endforeach
                    </ol>
                </div>
            @empty
                <p class="text-sm text-stone-500">No companions yet.</p>
            @endforelse
        </div>

        <dl class="mt-4 grid gap-1 text-sm sm:grid-cols-[8rem_1fr]">
            <dt class="text-stone-500">Title</dt>
            <dd>{{ $generation->title ?? '—' }}</dd>
            <dt class="text-stone-500">Description</dt>
            <dd>{{ $generation->description ?? '—' }}</dd>
        </dl>
    </section>

    <section class="mt-8">
        <h2 class="text-lg font-semibold">AI calls</h2>
        <div class="mt-3 overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-stone-200">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-stone-200 text-stone-500">
                    <tr>
                        <th class="px-3 py-2 font-medium">When</th>
                        <th class="px-3 py-2 font-medium">Stage</th>
                        <th class="px-3 py-2 font-medium">Provider · model</th>
                        <th class="px-3 py-2 text-right font-medium">In</th>
                        <th class="px-3 py-2 text-right font-medium">Out</th>
                        <th class="px-3 py-2 text-right font-medium">Cache write</th>
                        <th class="px-3 py-2 text-right font-medium">Cache read</th>
                        <th class="px-3 py-2 text-right font-medium">Cost</th>
                        <th class="px-3 py-2 text-right font-medium">Duration</th>
                        <th class="px-3 py-2 font-medium">Prompt</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 tabular-nums">
                    @forelse ($generation->aiUsages as $usage)
                        <tr wire:key="usage-{{ $usage->id }}">
                            <td class="px-3 py-2 whitespace-nowrap">{{ $usage->created_at?->format('H:i:s') }}</td>
                            <td class="px-3 py-2">{{ $usage->stage->value }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $usage->provider }} · {{ $usage->model }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($usage->input_tokens) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($usage->output_tokens) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($usage->cache_write_tokens) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($usage->cache_read_tokens) }}</td>
                            <td class="px-3 py-2 text-right">${{ number_format((float) $usage->cost_usd, 4) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($usage->duration_ms / 1000, 1) }}s</td>
                            <td class="px-3 py-2">
                                @if ($usage->promptVersion)
                                    @if ($canOpenPrompts)
                                        <a href="{{ route('studio.prompts.show', ['stage' => $usage->promptVersion->stage->value, 'version' => $usage->promptVersion->id]) }}" class="hover:underline">v{{ $usage->promptVersion->version }}</a>
                                    @else
                                        v{{ $usage->promptVersion->version }}
                                    @endif
                                @else
                                    —
                                @endif
                                @if ($usage->methodCoreVersion)
                                    <span class="whitespace-nowrap text-stone-500">
                                        + core
                                        @if ($canOpenPrompts)
                                            <a href="{{ route('studio.prompts.show', ['stage' => $usage->methodCoreVersion->stage->value, 'version' => $usage->methodCoreVersion->id]) }}" class="hover:underline">v{{ $usage->methodCoreVersion->version }}</a>
                                        @else
                                            v{{ $usage->methodCoreVersion->version }}
                                        @endif
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-3 py-6 text-center text-stone-500">No AI calls yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
