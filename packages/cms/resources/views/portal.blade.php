@extends('formaku::layout')

@section('content')
    <h1 class="text-4xl tracking-tight mb-8">Moje zgłoszenia</h1>
    @if (session('status'))
        <p role="status" class="text-success">{{ session('status') }}</p>
    @endif
    <div class="grid md:grid-cols-2 gap-8">
        <section>
            <h2 class="text-xl mb-4">Historia zgłoszeń</h2>
            @foreach ($records as $record)
                <a class="block p-5 bg-base-100 border border-base-300 rounded-lg mb-3" href="/portal/requests/{{ $record->id }}">
                    Zgłoszenie #{{ $record->id }} <span class="badge badge-outline">{{ $record->state }}</span>
                </a>
            @endforeach
            {{ $records->links() }}
        </section>
        <form method="post" action="/portal/requests" class="bg-base-100 p-6 border border-base-300 rounded-xl">
            @csrf
            <h2 class="text-xl mb-5">Jak możemy pomóc?</h2>
            @if ($errors->any())
                <p role="alert" class="text-error mb-4">{{ $errors->first() }}</p>
            @endif
            @php
                $fields = [
                    ['key' => 'title', 'label' => 'Temat', 'type' => 'text', 'required' => true, 'maxlength' => 200],
                    ['key' => 'description', 'label' => 'Opis', 'type' => 'textarea', 'required' => true, 'maxlength' => 5000],
                    ...($portalFields ?? []),
                ];
            @endphp
            @foreach ($fields as $field)
                @php
                    $key = $field['key'];
                    $type = $field['type'];
                    $id = 'portal-field-'.$key;
                    $required = $field['required'] ?? false;
                    $invalid = $errors->has($key);
                    $oldValue = old($key);
                    $value = match (true) {
                        is_bool($oldValue) => $oldValue ? '1' : '0',
                        is_scalar($oldValue) => (string) $oldValue,
                        default => '',
                    };
                @endphp
                <div class="mb-4">
                    <label for="{{ $id }}" class="field-label">{{ $field['label'] }}</label>
                    @if ($type === 'textarea')
                        <textarea id="{{ $id }}" name="{{ $key }}" maxlength="{{ $field['maxlength'] }}"
                            @class(['textarea w-full h-32', 'textarea-error' => $invalid])
                            @required($required)
                            @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>{{ $value }}</textarea>
                    @elseif ($type === 'boolean' || $type === 'select')
                        <select id="{{ $id }}" name="{{ $key }}"
                            @class(['select w-full', 'select-error' => $invalid])
                            @required($required)
                            @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
                            <option value="">Wybierz opcję</option>
                            @if ($type === 'boolean')
                                <option value="1" @selected($value === '1')>Tak</option>
                                <option value="0" @selected($value === '0')>Nie</option>
                            @else
                                @foreach ($field['options'] as $option)
                                    <option value="{{ $option }}" @selected($value === $option)>{{ $option }}</option>
                                @endforeach
                            @endif
                        </select>
                    @else
                        <input id="{{ $id }}" name="{{ $key }}" type="{{ $type }}" value="{{ $value }}"
                            @class(['input w-full', 'input-error' => $invalid])
                            @isset($field['maxlength']) maxlength="{{ $field['maxlength'] }}" @endisset
                            @if ($type === 'number') step="any" @endif
                            @required($required)
                            @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
                    @endif
                    @error($key)
                        <p id="{{ $id }}-error" class="text-error text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
            <button type="submit" class="btn btn-primary mt-1">Wyślij zgłoszenie</button>
        </form>
    </div>
@endsection
