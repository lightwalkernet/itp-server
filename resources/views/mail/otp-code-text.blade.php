Hello!

{!! $intro !!}

{{ $code }}

This code expires in {{ $lifetimeMinutes }} minutes.
@if ($warning)

{!! $warning !!}
@endif

{!! $ignore !!}
