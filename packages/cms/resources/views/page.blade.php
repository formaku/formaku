@extends('formaku::layout')

@section('content')
    @isset($pageAlternates)
        <nav class="mb-6" aria-label="{{ str_starts_with($pageLanguage, 'pl') ? 'Wersje językowe' : 'Language versions' }}">
            <ul class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
                @foreach ($pageAlternates as $alternate)
                    <li>
                        @if ($alternate['current'])
                            <span class="font-semibold" aria-current="page">{{ $alternate['locale'] }}</span>
                        @else
                            <a class="link" href="{{ $alternate['path'] }}" hreflang="{{ $alternate['locale'] }}">{{ $alternate['locale'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>
    @endisset
    <article class="prose">{!! $html !!}</article>
@endsection
