<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\PortalAttachment;
use Formaku\Cms\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PortalAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private const INPUT = '_portal_attachments';

    private const BASE = ['title' => 'Delivery request', 'description' => 'Synthetic request'];

    private array $fixtures = [];

    public function test_private_attachments_are_available_to_the_owner_and_staff_in_scope(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $collection = Collection::create($this->schema(['portal_attachments' => true, 'access' => [
            'customer' => ['scope' => 'own', 'write' => true],
            'editor' => ['scope' => 'assigned', 'write' => false],
        ]]));
        $owner = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $editor = User::factory()->create(['role' => 'editor']);
        $payload = "%PDF-1.4\n%%EOF\n";

        $this->actingAs($owner)->get('/portal')->assertOk()->assertViewHas('portalAttachmentsEnabled', true);
        $this->post('/portal/requests', self::BASE + [self::INPUT => [$this->pdf('Raport ąę.pdf', $payload)]])
            ->assertRedirect('/portal');
        $record = Record::sole();
        $attachment = PortalAttachment::sole();
        $this->assertSame($record->id, $attachment->record_id);
        $this->assertSame($owner->id, $record->owner_id);
        $this->assertSame($payload, Storage::disk('local')->get($attachment->path));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('formaku_media', 0);

        $portal = "/portal/requests/{$record->id}";
        $api = "/formaku/api/v1/records/{$record->id}";
        $metadata = ['id' => $attachment->id, 'name' => 'Raport ąę.pdf', 'size' => strlen($payload)];
        $portalUrl = "$portal/attachments/{$attachment->id}";
        $staffList = "$api/attachments";
        $staffUrl = "$staffList/{$attachment->id}";

        $this->get($portal)->assertOk()->assertViewHas('portalAttachments', [$metadata + ['url' => $portalUrl]]);
        $this->getJson($portal)->assertExactJson(['id' => $record->id, 'state' => 'new', 'data' => self::BASE]);
        $this->get($portalUrl)->assertOk()->assertDownload('Raport ae.pdf')
            ->assertHeader('Content-Disposition', "attachment; filename=\"Raport ae.pdf\"; filename*=utf-8''Raport%20%C4%85%C4%99.pdf")
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertStreamedContent($payload);
        $this->getJson($staffList)->assertForbidden();
        $this->get($staffUrl)->assertForbidden();
        $this->post('/portal/requests', self::BASE + [self::INPUT => [
            $this->pdf('📄'),
            UploadedFile::fake()->image('first.png', 5, 5),
            UploadedFile::fake()->image('second.jpg', 5, 5),
        ]])->assertRedirect('/portal');
        $second = Record::latest('id')->firstOrFail();
        $this->assertDatabaseCount('formaku_portal_attachments', 4);
        $unicodeAttachment = PortalAttachment::where('record_id', $second->id)->where('name', '📄')->sole();
        $this->get("/portal/requests/{$second->id}/attachments/{$unicodeAttachment->id}")
            ->assertOk()
            ->assertHeader('Content-Disposition', "attachment; filename=_; filename*=utf-8''%F0%9F%93%84");
        $this->get("/portal/requests/{$second->id}/attachments/{$attachment->id}")->assertNotFound();

        $this->actingAs($other)->get($portalUrl)->assertNotFound();
        $this->actingAs($editor)->getJson($staffList)->assertNotFound();
        $this->get($staffUrl)->assertNotFound();
        $record->update(['assignee_id' => $editor->id]);
        $this->getJson($staffList)->assertOk()->assertExactJson([$metadata + ['url' => $staffUrl]]);
        $this->getJson($api)->assertOk()->assertJsonMissingPath('portal_attachments');
        $this->get($staffUrl)->assertOk()->assertDownload('Raport ae.pdf')->assertStreamedContent($payload);

        $collection->update(['definition' => array_replace($collection->definition, ['portal_attachments' => false])]);
        $this->actingAs($owner)->get('/portal')->assertOk()->assertViewHas('portalAttachmentsEnabled', false);
        $this->get($portalUrl)->assertOk();
        $definition = $collection->definition;
        unset($definition['access']['customer']);
        $collection->update(['definition' => $definition]);
        $this->get($portal)->assertOk()->assertViewHas('portalAttachments', []);
        $this->get($portalUrl)->assertNotFound();
    }

    public function test_invalid_or_disabled_uploads_do_not_create_records_or_store_files(): void
    {
        Storage::fake('local');
        $customer = User::factory()->create(['role' => 'customer']);
        $collection = Collection::create($this->schema());

        $this->actingAs($customer)->get('/portal')->assertOk()->assertViewHas('portalAttachmentsEnabled', false);
        $this->post('/portal/requests', self::BASE + [self::INPUT => [$this->pdf()]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors([self::INPUT]);

        $collection->update(['definition' => $collection->definition + ['portal_attachments' => true, 'access' => [
            'customer' => ['scope' => 'assigned', 'write' => true],
        ]]]);
        $this->get('/portal')->assertOk()->assertViewHas('portalAttachmentsEnabled', false);
        $this->post('/portal/requests', self::BASE + [self::INPUT => [$this->pdf()]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors([self::INPUT]);

        $definition = $collection->definition;
        unset($definition['access']);
        $collection->update(['definition' => $definition]);
        $this->post('/portal/requests', self::BASE + [self::INPUT => [
            $this->pdf('notes.pdf', 'Synthetic plain text only'),
            UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf'),
        ]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors([self::INPUT.'.0', self::INPUT.'.1']);
        $this->post('/portal/requests', self::BASE + [self::INPUT => [$this->pdf(), $this->pdf(), $this->pdf(), $this->pdf()]], [
            'Accept' => 'application/json',
        ])->assertUnprocessable()->assertJsonValidationErrors([self::INPUT]);
        $this->postJson('/portal/requests', self::BASE + [self::INPUT => 'filename.pdf'])
            ->assertUnprocessable()->assertJsonValidationErrors([self::INPUT]);

        $this->assertDatabaseCount('formaku_records', 0);
        $this->assertDatabaseCount('formaku_portal_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->post('/portal/requests', self::BASE)->assertRedirect('/portal');
        $this->assertDatabaseCount('formaku_records', 1);
        $record = Record::sole();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson("/formaku/api/v1/records/{$record->id}/attachments")->assertOk()->assertExactJson([]);
    }

    public function test_failure_while_linking_a_file_rolls_back_the_request_and_cleans_up_written_files(): void
    {
        Storage::fake('local');
        Collection::create($this->schema(['portal_attachments' => true]));
        $customer = User::factory()->create(['role' => 'customer']);
        $attempted = [];
        Event::listen('eloquent.creating: '.PortalAttachment::class, function (PortalAttachment $attachment) use (&$attempted): void {
            $attempted[] = $attachment->name;
            if ($attachment->name === 'second.pdf') {
                throw new RuntimeException('Synthetic attachment database failure.');
            }
        });

        $this->actingAs($customer)->post('/portal/requests', self::BASE + [self::INPUT => [
            $this->pdf('first.pdf'),
            $this->pdf('second.pdf'),
        ]])->assertServerError();

        $this->assertDatabaseCount('formaku_records', 0);
        $this->assertSame(['first.pdf', 'second.pdf'], $attempted);
        $this->assertDatabaseCount('formaku_revisions', 0);
        $this->assertDatabaseCount('formaku_portal_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_only_request_collection_models_can_opt_in_through_save_or_import(): void
    {
        $builder = User::factory()->create(['role' => 'admin']);
        $this->actingAs($builder)->postJson('/formaku/api/v1/collections', $this->schema(['portal_attachments' => 'yes']))
            ->assertUnprocessable()->assertJsonValidationErrors(['definition.portal_attachments']);
        $this->postJson('/formaku/api/v1/collections', $this->schema(['portal_attachments' => true], 'other'))
            ->assertUnprocessable()->assertJsonValidationErrors(['definition.portal_attachments']);
        $this->postJson('/formaku/api/v1/project/import', [
            'format' => 'formaku.project', 'version' => 1, 'collections' => [$this->schema(['portal_attachments' => true], 'other')],
        ])->assertUnprocessable()->assertJsonValidationErrors(['definition.portal_attachments']);
        $this->postJson('/formaku/api/v1/collections', $this->schema(['portal_attachments' => true]))
            ->assertCreated()->assertJsonPath('definition.portal_attachments', true);
    }

    public function test_configured_attachment_limits_apply_to_the_portal_view_and_uploads(): void
    {
        Storage::fake('local');
        Collection::create($this->schema([
            'portal_attachments' => true,
            'portal_attachment_limits' => ['max_files' => 1, 'max_mib' => 1, 'types' => ['pdf']],
        ]));
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $this->get('/portal')->assertOk()
            ->assertViewHas('portalAttachmentsEnabled', true)
            ->assertViewHas('portalAttachmentLimits', [
                'max_files' => 1, 'max_mib' => 1, 'types' => ['pdf'], 'accept' => '.pdf,application/pdf',
            ]);

        $this->post('/portal/requests', self::BASE + [self::INPUT => [$this->pdf(), $this->pdf()]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors([self::INPUT]);
        $this->post('/portal/requests', self::BASE + [self::INPUT => [
            UploadedFile::fake()->create('too-large.pdf', 1025, 'application/pdf'),
        ]], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors([self::INPUT.'.0']);
        $this->post('/portal/requests', self::BASE + [self::INPUT => [
            UploadedFile::fake()->image('excluded.png'),
        ]], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors([self::INPUT.'.0']);
        $this->assertDatabaseCount('formaku_records', 0);
        $this->assertDatabaseCount('formaku_revisions', 0);
        $this->assertDatabaseCount('formaku_portal_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->post('/portal/requests', self::BASE + [self::INPUT => [$this->pdf()]])->assertRedirect('/portal');
        $this->assertDatabaseCount('formaku_records', 1);
        $this->assertDatabaseCount('formaku_portal_attachments', 1);
        Storage::disk('local')->assertExists(PortalAttachment::sole()->path);
    }

    public function test_legacy_or_corrupt_attachment_settings_resolve_to_bounded_defaults(): void
    {
        $collection = Collection::create($this->schema(['portal_attachments' => true]));
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $defaults = [
            'max_files' => 3, 'max_mib' => 5, 'types' => ['pdf', 'png', 'jpeg'],
            'accept' => '.pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg',
        ];
        $this->get('/portal')->assertOk()->assertViewHas('portalAttachmentLimits', $defaults);

        foreach (['invalid', ['max_files' => '1', 'max_mib' => 6, 'types' => ['pdf', ['bad']]]] as $limits) {
            $collection->update(['definition' => array_replace($collection->definition, ['portal_attachment_limits' => $limits])]);
            $this->get('/portal')->assertOk()->assertViewHas('portalAttachmentLimits', $defaults);
        }

        $collection->update(['definition' => array_replace($collection->definition, [
            'portal_attachment_limits' => ['max_files' => 2, 'types' => ['jpeg']],
        ])]);
        $this->get('/portal')->assertOk()->assertViewHas('portalAttachmentLimits', [
            'max_files' => 2, 'max_mib' => 5, 'types' => ['jpeg'], 'accept' => '.jpg,.jpeg,image/jpeg',
        ]);
    }

    public function test_attachment_limits_are_validated_and_preserved_when_disabled(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $field = 'definition.portal_attachment_limits';
        $api = '/formaku/api/v1/collections';
        foreach ([
            [null, $field],
            [['unknown' => true], $field],
            [['pdf'], $field],
            [['max_files' => 0], $field.'.max_files'],
            [['max_files' => 4], $field.'.max_files'],
            [['max_files' => '2'], $field.'.max_files'],
            [['max_mib' => 0], $field.'.max_mib'],
            [['max_mib' => 6], $field.'.max_mib'],
            [['max_mib' => 1.5], $field.'.max_mib'],
            [['types' => []], $field.'.types'],
            [['types' => [1 => 'pdf']], $field.'.types'],
            [['types' => ['pdf', 'pdf']], $field.'.types.0'],
            [['types' => ['gif']], $field.'.types.0'],
        ] as [$limits, $error]) {
            $this->postJson($api, $this->schema(['portal_attachment_limits' => $limits]))
                ->assertUnprocessable()->assertJsonValidationErrors($error);
        }

        $limits = ['max_files' => 2, 'max_mib' => 3, 'types' => ['png', 'jpeg']];
        $definition = $this->schema(['portal_attachments' => true, 'portal_attachment_limits' => $limits]);
        $this->postJson($api, array_replace($definition, ['kind' => 'content']))
            ->assertUnprocessable()->assertJsonValidationErrors('definition.portal_attachments');
        $this->postJson($api, $this->schema(['portal_attachment_limits' => $limits], 'other'))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('formaku_collections', 0);
        $saved = $this->postJson($api, $definition)->assertCreated()
            ->assertJsonPath($field.'.max_files', 2)
            ->assertJsonPath($field.'.max_mib', 3)
            ->assertJsonPath($field.'.types', ['png', 'jpeg'])->json();
        $definition['definition']['portal_attachments'] = false;
        $this->postJson($api, $definition + ['id' => $saved['id'], 'version' => $saved['version']])
            ->assertOk()->assertJsonPath('definition.portal_attachments', false)
            ->assertJsonPath($field.'.max_files', 2)
            ->assertJsonPath($field.'.max_mib', 3)
            ->assertJsonPath($field.'.types', ['png', 'jpeg']);

        $this->actingAs(User::factory()->create(['role' => 'customer']))->get('/portal')->assertOk()
            ->assertViewHas('portalAttachmentsEnabled', false)
            ->assertViewHas('portalAttachmentLimits', $limits + ['accept' => '.png,.jpg,.jpeg,image/png,image/jpeg']);
    }

    public function test_project_import_applies_the_same_attachment_limit_rules(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $api = '/formaku/api/v1/project/import';
        $format = ['format' => 'formaku.project', 'version' => 1];
        $field = 'definition.portal_attachment_limits';
        $limits = ['max_files' => 2, 'types' => ['png']];
        $this->postJson($api, $format + ['collections' => [$this->schema(['portal_attachment_limits' => $limits], 'other')]])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->postJson($api, $format + ['collections' => [$this->schema(['portal_attachment_limits' => ['max_mib' => '5']])]])
            ->assertUnprocessable()->assertJsonValidationErrors($field.'.max_mib');
        $this->assertDatabaseCount('formaku_collections', 0);

        $this->postJson($api, $format + ['collections' => [$this->schema([
            'portal_attachments' => false, 'portal_attachment_limits' => $limits,
        ])]])->assertOk()->assertJsonPath('imported', 1);
        $this->getJson('/formaku/api/v1/project')->assertOk()
            ->assertJsonPath('collections.0.'.$field.'.max_files', 2)
            ->assertJsonMissingPath('collections.0.'.$field.'.max_mib')
            ->assertJsonPath('collections.0.'.$field.'.types', ['png'])
            ->assertJsonPath('collections.0.definition.portal_attachments', false);
    }

    private function schema(array $settings = [], string $key = 'requests'): array
    {
        return [
            'key' => $key, 'name' => 'Requests', 'kind' => 'data',
            'definition' => $settings + ['fields' => [
                ['key' => 'title', 'label' => 'Subject', 'type' => 'text'],
                ['key' => 'description', 'label' => 'Description', 'type' => 'text'],
            ]],
        ];
    }

    private function pdf(string $name = 'test.pdf', string $content = "%PDF-1.4\n%%EOF\n"): UploadedFile
    {
        $file = UploadedFile::fake()->createWithContent($name, $content);
        $this->fixtures[] = $file;

        return new UploadedFile($file->getPathname(), $name, 'application/pdf', test: true);
    }
}
