<main class="mx-auto max-w-6xl px-6 py-10">
    <h1 class="text-2xl font-semibold">Costs</h1>
    <p class="mt-2 max-w-3xl text-stone-600">
        What the AI calls cost, in US dollars. Each call's cost is worked out when it is made, from its token counts and the price table at the bottom of this page (<span class="font-mono text-sm">config/itp.php</span>, <span class="font-mono text-sm">ai.prices</span>). Days run midnight to midnight UTC.
    </p>

    <section class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($this->totals as $key => $total)
            <div wire:key="total-{{ $key }}" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-stone-200">
                <h2 class="text-sm font-medium text-stone-500">{{ $total['label'] }}</h2>
                <p class="mt-1 text-2xl font-semibold tabular-nums">${{ number_format($total['cost'], 2) }}</p>
                <p class="mt-2 text-xs text-stone-500 tabular-nums">
                    {{ number_format($total['calls']) }} calls ·
                    {{ number_format($total['input']) }} in ·
                    {{ number_format($total['output']) }} out ·
                    {{ number_format($total['cache_write']) }} cache write ·
                    {{ number_format($total['cache_read']) }} cache read
                </p>
            </div>
        @endforeach
    </section>

    <div class="mt-10 flex flex-wrap items-center gap-3">
        <label for="range" class="text-sm font-medium">Show breakdowns for</label>
        <select wire:model.live="range" id="range" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm focus:border-stone-500 focus:outline-none focus:ring-2 focus:ring-stone-200">
            @foreach ($ranges as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <section class="mt-6 rounded-lg bg-white p-5 shadow-sm ring-1 ring-stone-200">
        <h2 class="font-semibold">Cost of a finished personal ITP</h2>
        <p class="mt-1 text-sm text-stone-500">
            Every AI call a generation made, follow-ups, rewritten sections and retries included, for the {{ number_format($this->perCompletedItp['count']) }} personal {{ Str::plural('ITP', $this->perCompletedItp['count']) }} completed in this range.
        </p>
        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-stone-500">Average per ITP</dt>
                <dd class="text-2xl font-semibold tabular-nums">{{ $this->perCompletedItp['average'] === null ? '—' : '$'.number_format($this->perCompletedItp['average'], 4) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-stone-500">Median per ITP</dt>
                <dd class="text-2xl font-semibold tabular-nums">{{ $this->perCompletedItp['median'] === null ? '—' : '$'.number_format($this->perCompletedItp['median'], 4) }}</dd>
            </div>
        </dl>
    </section>

    @php
        $tokenHeadings = ['Calls', 'In', 'Out', 'Cache write', 'Cache read', 'Total cost', 'Avg per call'];
    @endphp

    <section class="mt-8">
        <h2 class="text-lg font-semibold">By stage</h2>
        <div class="mt-3 overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-stone-200">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-stone-200 text-stone-500">
                    <tr>
                        <th class="px-3 py-2 font-medium">Stage</th>
                        @foreach ($tokenHeadings as $heading)
                            <th class="px-3 py-2 text-right font-medium">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 tabular-nums">
                    @forelse ($this->byStage as $row)
                        <tr wire:key="stage-{{ $row['stage'] }}">
                            <td class="px-3 py-2">{{ $row['stage'] }}</td>
                            @include('livewire.studio.partials.cost-cells', ['row' => $row])
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center text-stone-500">No AI calls in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-8">
        <h2 class="text-lg font-semibold">By provider and model</h2>
        <div class="mt-3 overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-stone-200">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-stone-200 text-stone-500">
                    <tr>
                        <th class="px-3 py-2 font-medium">Provider</th>
                        <th class="px-3 py-2 font-medium">Model</th>
                        @foreach ($tokenHeadings as $heading)
                            <th class="px-3 py-2 text-right font-medium">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 tabular-nums">
                    @forelse ($this->byModel as $row)
                        <tr wire:key="model-{{ $row['provider'] }}-{{ $row['model'] }}">
                            <td class="px-3 py-2">{{ $row['provider'] }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $row['model'] }}</td>
                            @include('livewire.studio.partials.cost-cells', ['row' => $row])
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-3 py-6 text-center text-stone-500">No AI calls in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-8">
        <h2 class="text-lg font-semibold">Top users</h2>
        <div class="mt-3 overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-stone-200">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-stone-200 text-stone-500">
                    <tr>
                        <th class="px-3 py-2 font-medium">User</th>
                        <th class="px-3 py-2 text-right font-medium">Generations</th>
                        <th class="px-3 py-2 text-right font-medium">Total cost</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 tabular-nums">
                    @forelse ($this->topUsers as $row)
                        <tr wire:key="user-{{ $row['email'] }}">
                            <td class="px-3 py-2">{{ $row['email'] }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($row['generations']) }}</td>
                            <td class="px-3 py-2 text-right">${{ number_format($row['cost'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-3 py-6 text-center text-stone-500">No AI calls in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-8">
        <h2 class="text-lg font-semibold">By day</h2>
        <div class="mt-3 overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-stone-200">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-stone-200 text-stone-500">
                    <tr>
                        <th class="px-3 py-2 font-medium">Day (UTC)</th>
                        @foreach ($tokenHeadings as $heading)
                            <th class="px-3 py-2 text-right font-medium">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 tabular-nums">
                    @forelse ($this->daily as $row)
                        <tr wire:key="day-{{ $row['day'] }}">
                            <td class="px-3 py-2 whitespace-nowrap">{{ $row['day'] }}</td>
                            @include('livewire.studio.partials.cost-cells', ['row' => $row])
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center text-stone-500">No AI calls in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-10">
        <h2 class="text-lg font-semibold">Price table</h2>
        <p class="mt-1 text-sm text-stone-500">
            USD per million tokens, from <span class="font-mono">config/itp.php</span> (<span class="font-mono">ai.prices</span>). Thinking tokens bill as output; cache write is the 5-minute rate. A call to a model missing here is logged at $0, so check this against the provider's price page.
        </p>
        <div class="mt-3 overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-stone-200">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-stone-200 text-stone-500">
                    <tr>
                        <th class="px-3 py-2 font-medium">Model</th>
                        <th class="px-3 py-2 text-right font-medium">Input</th>
                        <th class="px-3 py-2 text-right font-medium">Output</th>
                        <th class="px-3 py-2 text-right font-medium">Cache write</th>
                        <th class="px-3 py-2 text-right font-medium">Cache read</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 tabular-nums">
                    @foreach ($prices as $model => $price)
                        <tr wire:key="price-{{ $model }}">
                            <td class="px-3 py-2 font-mono text-xs">{{ $model }}</td>
                            <td class="px-3 py-2 text-right">${{ number_format($price['input'], 2) }}</td>
                            <td class="px-3 py-2 text-right">${{ number_format($price['output'], 2) }}</td>
                            <td class="px-3 py-2 text-right">${{ number_format($price['cache_write'], 3) }}</td>
                            <td class="px-3 py-2 text-right">${{ number_format($price['cache_read'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</main>
