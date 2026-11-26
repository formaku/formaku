<?php

namespace Formaku\Cms\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PortalBlueprint
{
    public function reference(): array
    {
        return [
            'name' => 'Zapytania ofertowe B2B',
            'pages' => [
                ['slug' => 'uslugi-b2b', 'title' => 'Usługi dla firm', 'body' => 'Opisz zakres potrzebnej usługi i preferowany termin. Zespół przeanalizuje wymagania, a odpowiedź znajdziesz w swoim portalu.', 'cta' => 'Złóż zapytanie ofertowe'],
            ],
            'fields' => [
                ['key' => 'service', 'label' => 'Usługa', 'type' => 'text', 'options' => []],
                ['key' => 'desired_date', 'label' => 'Preferowany termin', 'type' => 'date', 'options' => []],
            ],
            'states' => ['new', 'in_progress', 'closed'],
            'questions' => ['Jakie usługi ma obejmować formularz?', 'Czy firma deklaruje termin odpowiedzi?'],
        ];
    }

    public function validate(array $proposal): array
    {
        $reserved = ['title', 'description', 'customer_reply', 'internal_note', 'attachments', '_token'];
        $data = Validator::make(['project' => $proposal], [
            'project' => 'required|array:name,pages,fields,states,questions',
            'project.name' => 'required|string|max:100',
            'project.pages' => 'required|array|list|min:1|max:8',
            'project.pages.*' => 'required|array:title,slug,body,cta',
            'project.pages.*.title' => 'required|string|max:200',
            'project.pages.*.slug' => 'required|string|distinct:strict|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D|max:100',
            'project.pages.*.body' => 'required|string|max:4000',
            'project.pages.*.cta' => 'required|string|max:100',
            'project.fields' => 'present|array|list|max:12',
            'project.fields.*' => 'required|array:key,label,type,options',
            'project.fields.*.key' => ['required', 'string', 'distinct:strict', 'regex:/^[a-z][a-z0-9_]{0,48}$/D', 'not_in:'.implode(',', $reserved)],
            'project.fields.*.label' => 'required|string|max:100',
            'project.fields.*.type' => 'required|in:text,number,date,boolean,select',
            'project.fields.*.options' => 'present|array|list|max:30',
            'project.fields.*.options.*' => 'required|string|max:100',
            'project.states' => 'required|array|list|min:2|max:8',
            'project.states.*' => 'required|string|distinct:strict|regex:/^[a-z][a-z0-9_]{0,48}$/D',
            'project.questions' => 'present|array|list|max:12',
            'project.questions.*' => 'required|string|max:500',
        ])->validate()['project'];

        foreach ($data['fields'] as $field) {
            if (($field['type'] !== 'select' && $field['options'] !== [])
                || ! isset(PortalFields::eligibleFields([$field])[$field['key']])) {
                throw ValidationException::withMessages(['fields' => 'Use distinct select options without commas. Other field types take no options.']);
            }
        }

        return $data;
    }

    /** The response and note are always owned by staff, independently of model output. */
    public function requestsDefinition(array $proposal, ?array $old = null): array
    {
        $staff = config('formaku.portal_staff_roles', ['admin', 'editor', 'publisher']);
        $fields = [
            ['key' => 'title', 'label' => 'Temat', 'type' => 'text', 'required' => true],
            ['key' => 'description', 'label' => 'Opis', 'type' => 'text', 'required' => true],
            ['key' => 'customer_reply', 'label' => 'Odpowiedź dla klienta', 'type' => 'text', 'access' => ['write' => $staff]],
            ['key' => 'internal_note', 'label' => 'Notatka wewnętrzna', 'type' => 'text', 'private' => true, 'access' => ['read' => $staff, 'write' => $staff]],
        ];
        foreach ($proposal['fields'] as $field) {
            if ($field['type'] !== 'select') {
                unset($field['options']);
            }
            $fields[] = $field;
        }

        $existing = collect($old['fields'] ?? [])->keyBy('key')->all();
        foreach ($fields as $field) {
            $key = $field['key'];
            if (! isset($existing[$key])) {
                $existing[$key] = $field;

                continue;
            }
            $original = $existing[$key];
            abort_unless($original['type'] === $field['type']
                && ($original['private'] ?? false) === ($field['private'] ?? false), 409, 'Existing field is incompatible: '.$key);
            if (isset($field['options'])) {
                abort_unless($field['options'] === ($original['options'] ?? []), 409, 'Select options conflict: '.$key);
            }
            if (isset($field['access'])) {
                foreach ($field['access'] as $operation => $roles) {
                    $existing[$key]['access'][$operation] = array_values(array_intersect($original['access'][$operation] ?? $roles, $roles));
                }
            }
        }

        $states = $proposal['states'];
        $transitions = [];
        foreach (array_slice($states, 1) as $i => $state) {
            $transitions[] = ['from' => $states[$i], 'to' => $state];
        }

        $definition = $old ?? [];
        $definition['fields'] = array_values($existing);
        $definition['portal_fields'] = array_values(array_unique(array_merge($old['portal_fields'] ?? [], array_column($proposal['fields'], 'key'))));
        $definition['portal_reply'] = true;
        $definition['states'] = $states;
        $definition['transitions'] = $transitions;

        return $definition;
    }

    public function pageDefinition(): array
    {
        return ['public' => true, 'fields' => [
            ['key' => 'title', 'type' => 'text', 'required' => true, 'label' => 'Tytuł'],
            ['key' => 'slug', 'type' => 'text', 'required' => true, 'label' => 'Adres URL'],
            ['key' => 'summary', 'type' => 'text', 'label' => 'Opis'],
            ['key' => 'blocks', 'type' => 'richtext', 'label' => 'Treść'],
        ], 'seo' => ['title' => 'title', 'description' => 'summary']];
    }

    public function pageData(array $page, string $portalUrl = '/portal'): array
    {
        return ['title' => $page['title'], 'slug' => $page['slug'], 'summary' => mb_substr($page['body'], 0, 160),
            'blocks' => [
                ['type' => 'hero', 'props' => ['title' => $page['title'], 'text' => $page['body']]],
                ['type' => 'cta', 'props' => ['title' => 'Kontakt', 'label' => $page['cta'], 'url' => $portalUrl]],
            ],
        ];
    }
}
