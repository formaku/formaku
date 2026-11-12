@extends('formaku::layout')

@section('content')
    <h1 class="text-4xl tracking-tight mb-8">Moje zgłoszenia</h1>
    @if (session('status'))
        <p role="status" class="text-success">{{ session('status') }}</p>
    @endif
    <div class="grid md:grid-cols-2 gap-8">
        <section>
            <h2 class="text-xl mb-4">Historia zgłoszeń</h2>
            <form method="get" action="{{ url('/portal') }}" class="mb-4 space-y-3" aria-label="Filtry historii zgłoszeń">
                <div @class(['grid gap-3', 'sm:grid-cols-2' => ($portalStates ?? []) !== []])>
                    <div>
                        <label for="portal-request-id" class="field-label">Numer zgłoszenia</label>
                        <input id="portal-request-id" name="request_id" type="text" inputmode="numeric" autocomplete="off"
                            value="{{ $portalRequestId ?? '' }}"
                            @class(['input w-full', 'input-error' => $portalRequestIdInvalid ?? false])
                            @if ($portalRequestIdInvalid ?? false) aria-invalid="true" aria-describedby="portal-request-id-error" @endif>
                        @if ($portalRequestIdInvalid ?? false)
                            <p id="portal-request-id-error" class="mt-1 text-sm text-error">Wpisz prawidłowy numer zgłoszenia, np. 123.</p>
                        @endif
                    </div>
                    @if (($portalStates ?? []) !== [])
                        <div>
                            <label for="portal-state" class="field-label">Stan zgłoszenia</label>
                            <select id="portal-state" name="state" class="select w-full">
                                <option value="">Wszystkie stany</option>
                                @foreach ($portalStates as $state)
                                    <option value="{{ $state }}" @selected(($portalState ?? null) === $state)>{{ $state }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="btn">Filtruj</button>
                    @if (($portalRequestId ?? '') !== '' || ($portalState ?? '') !== '' || ($portalRequestIdInvalid ?? false))
                        <a href="{{ url('/portal') }}" class="link">Wyczyść filtry</a>
                    @endif
                </div>
            </form>
            @forelse ($records as $record)
                <a class="block p-5 bg-base-100 border border-base-300 rounded-lg mb-3" href="/portal/requests/{{ $record->id }}">
                    <span class="flex flex-wrap items-center gap-2">
                        Zgłoszenie #{{ $record->id }} <span class="badge badge-outline">{{ $record->state }}</span>
                    </span>
                    @if (($portalRequestTitles[$record->id] ?? '') !== '')
                        <span class="mt-1 block text-sm wrap-anywhere">{{ $portalRequestTitles[$record->id] }}</span>
                    @endif
                </a>
            @empty
                <p class="text-base-content/70">Brak zgłoszeń na tej stronie.</p>
            @endforelse
            {{ $records->links() }}
        </section>
        <form method="post" action="/portal/requests"
            @if ($portalAttachmentsEnabled ?? false) enctype="multipart/form-data" @endif
            class="bg-base-100 p-6 border border-base-300 rounded-xl">
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
            @if ($portalAttachmentsEnabled ?? false)
                @php
                    $attachmentErrors = array_unique(array_merge(
                        $errors->get('_portal_attachments'),
                        ...array_values($errors->get('_portal_attachments.*')),
                    ));
                    $invalidAttachments = $attachmentErrors !== [];
                @endphp
                <div class="mb-4">
                    <label for="portal-attachments" class="field-label">Załączniki do zgłoszenia</label>
                    <input id="portal-attachments" name="_portal_attachments[]" type="file"
                        @if ($portalAttachmentLimits['max_files'] > 1) multiple @endif
                        accept="{{ $portalAttachmentLimits['accept'] }}"
                        @class(['file-input w-full', 'file-input-error' => $invalidAttachments])
                        aria-describedby="portal-attachments-hint{{ $invalidAttachments ? ' portal-attachments-errors' : '' }}"
                        @if ($invalidAttachments) aria-invalid="true" @endif>
                    <p id="portal-attachments-hint" class="mt-1 text-xs text-base-content/70">
                        Możesz dodać maksymalnie {{ $portalAttachmentLimits['max_files'] }}
                        {{ $portalAttachmentLimits['max_files'] === 1 ? 'plik' : 'pliki' }}
                        ({{ implode(', ', array_map('strtoupper', $portalAttachmentLimits['types'])) }}).
                        Limit na plik wynosi {{ $portalAttachmentLimits['max_mib'] }} MiB.
                    </p>
                    @if ($invalidAttachments)
                        <ul id="portal-attachments-errors" class="mt-1 text-sm text-error">
                            @foreach ($attachmentErrors as $attachmentError)
                                <li>{{ $attachmentError }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
            <button type="submit" class="btn btn-primary mt-1">Wyślij zgłoszenie</button>
        </form>
    </div>
@endsection
