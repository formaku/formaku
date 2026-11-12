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
