<main class="mx-auto max-w-6xl px-6 py-10">
    <h1 class="text-2xl font-semibold">Generations</h1>
    <p class="mt-2 text-stone-600">Every run of the pipeline, newest first. Open one to see the answers, what each stage wrote and what it cost.</p>

    <div class="mt-8 overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-stone-200">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-stone-200 text-stone-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Created</th>
                    <th class="px-4 py-2 font-medium">User</th>
                    <th class="px-4 py-2 font-medium">Status</th>
                    <th class="px-4 py-2 text-right font-medium">Total cost</th>
                    <th class="px-4 py-2 font-medium">Prompt versions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($generations as $generation)
                    <tr wire:key="generation-{{ $generation->id }}" class="hover:bg-stone-50">
                        <td class="px-4 py-2 whitespace-nowrap">
                            <a href="{{ route('studio.generations.show', $generation) }}" class="font-medium hover:underline">
                                {{ $generation->created_at->format('j M Y, H:i') }}
                            </a>
                        </td>
                        <td class="px-4 py-2">{{ $generation->user?->email ?? '—' }}</td>
                        <td class="px-4 py-2"><x-studio.status :status="$generation->status" /></td>
                        <td class="px-4 py-2 text-right tabular-nums">${{ number_format((float) $generation->total_cost, 2) }}</td>
                        <td class="px-4 py-2 font-mono text-xs text-stone-600">
                            @foreach ($generation->prompt_versions ?? [] as $stage => $promptVersionId)
                                <span class="mr-2 whitespace-nowrap">{{ $stage }} v{{ $promptVersions->get($promptVersionId)?->version ?? '?' }}</span>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-stone-500">No generations yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $generations->links() }}
    </div>
</main>
