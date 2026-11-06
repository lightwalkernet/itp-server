<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? $title.' · ' : '' }}ITP Studio</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 font-sans text-stone-900 antialiased">
    @auth('studio')
        @php
            $staff = auth('studio')->user();
            $navigation = array_filter([
                ['label' => 'Dashboard', 'href' => route('studio.dashboard'), 'active' => request()->routeIs('studio.dashboard')],
                $staff->can('managePrompts') ? ['label' => 'Prompts', 'href' => route('studio.prompts.index'), 'active' => request()->routeIs('studio.prompts.*')] : null,
                ['label' => 'Generations', 'href' => route('studio.generations.index'), 'active' => request()->routeIs('studio.generations.*')],
                $staff->can('viewCosts') ? ['label' => 'Costs', 'href' => route('studio.costs'), 'active' => request()->routeIs('studio.costs')] : null,
                $staff->can('viewHorizon') ? ['label' => 'Horizon', 'href' => route('horizon.index'), 'active' => false] : null,
            ]);
        @endphp

        <header class="border-b border-stone-200 bg-white">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-4">
                <div class="flex flex-wrap items-center gap-6">
                    <span class="text-sm font-semibold uppercase tracking-widest text-stone-500">ITP Studio</span>

                    <nav class="flex flex-wrap items-center gap-4 text-sm">
                        @foreach ($navigation as $item)
                            <a
                                href="{{ $item['href'] }}"
                                @class([
                                    'font-medium text-stone-900 underline decoration-2 underline-offset-8' => $item['active'],
                                    'text-stone-500 hover:text-stone-900' => ! $item['active'],
                                ])
                            >{{ $item['label'] }}</a>
                        @endforeach
                    </nav>
                </div>

                <div class="flex items-center gap-4 text-sm">
                    <span class="text-stone-600">{{ $staff->name }} · {{ $staff->role->label() }}</span>

                    <form method="POST" action="{{ route('studio.logout') }}">
                        @csrf
                        <button type="submit" class="text-stone-500 hover:text-stone-900">Sign out</button>
                    </form>
                </div>
            </div>
        </header>
    @endauth

    {{ $slot }}
</body>
</html>
