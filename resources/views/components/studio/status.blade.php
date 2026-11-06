@props(['status'])

<span @class([
    'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
    'bg-emerald-100 text-emerald-800' => $status === \App\Enums\ItpStatus::Complete,
    'bg-red-100 text-red-800' => in_array($status, [\App\Enums\ItpStatus::Failed, \App\Enums\ItpStatus::Blocked], true),
    'bg-amber-100 text-amber-800' => $status === \App\Enums\ItpStatus::Deferred || $status->awaitsUser(),
    'bg-sky-100 text-sky-800' => $status->isInProgress(),
])>{{ str_replace('_', ' ', $status->value) }}</span>
