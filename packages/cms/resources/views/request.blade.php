@extends('formaku::layout')

@section('content')
    <a href="/portal" class="text-primary">← Wszystkie zgłoszenia</a>
    <article class="mt-8 bg-base-100 border border-base-300 rounded-xl p-8">
        <span class="badge badge-outline">{{ $record['state'] }}</span>
        <h1 class="text-3xl mt-6">{{ $record['data']['title'] ?? 'Zgłoszenie' }}</h1>
        <p class="mt-5 whitespace-pre-wrap">{{ $record['data']['description'] ?? '' }}</p>
        @if (! empty($portalFields))
            <dl class="mt-6 space-y-4 border-t border-base-300 pt-6">
                @foreach ($portalFields as $field)
                    @php($value = $record['data'][$field['key']] ?? null)
                    @if (is_scalar($value))
                        <div>
                            <dt class="font-medium wrap-anywhere">{{ $field['label'] }}</dt>
                            <dd class="whitespace-pre-wrap wrap-anywhere">{{ $field['type'] === 'boolean' ? ((bool) $value ? 'Tak' : 'Nie') : $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        @endif
    </article>
@endsection
