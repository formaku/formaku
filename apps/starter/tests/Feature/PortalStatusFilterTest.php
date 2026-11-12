<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_owned_requests_by_exact_legacy_state_and_preserves_it_across_pages(): void
    {
        $this->freezeTime();
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $requests = $this->createCollection('requests');
        $otherCollection = $this->createCollection('other');
        $legacy = 'legacy queued';
        $matching = [];
        for ($i = 0; $i < 21; $i++) {
            $matching[] = $this->createRecord($requests, $customer, $legacy)->id;
        }

        foreach (['Legacy queued', 'legacy queuéd', 'new', ''] as $state) {
            $this->createRecord($requests, $customer, $state);
        }

        $this->createRecord($requests, $otherCustomer, $legacy);
        $this->createRecord($requests, $otherCustomer, 'other customer');
        $this->createRecord($otherCollection, $customer, $legacy);
        $this->createRecord($otherCollection, $customer, 'other collection');

        $firstPage = $this->actingAs($customer)
            ->get('/portal?state=legacy%20queued&unrelated=1')
            ->assertOk()
            ->assertViewHas('portalStates', ['Legacy queued', $legacy, 'legacy queuéd', 'new'])
            ->assertViewHas('portalState', $legacy)
            ->viewData('records');

        $this->assertSame(21, $firstPage->total());
        $this->assertSame(array_reverse(array_slice($matching, 1)), $firstPage->getCollection()->modelKeys());
        $this->assertSame(url('/portal').'?state=legacy%20queued&page=2', $firstPage->nextPageUrl());

        $secondPage = $this->get($firstPage->nextPageUrl())
            ->assertOk()
            ->assertViewHas('portalState', $legacy)
            ->viewData('records');

        $this->assertSame([$matching[0]], $secondPage->getCollection()->modelKeys());
    }

    public function test_missing_or_invalid_state_shows_all_owned_requests(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $requests = $this->createCollection('requests');
        $opened = $this->createRecord($requests, $customer, 'new');
        $closed = $this->createRecord($requests, $customer, 'closed');
        $this->createRecord($requests, $otherCustomer, 'private state');

        $this->actingAs($customer);
        foreach (['', '?state=', '?state=NEW', '?state%5B%5D=new', '?state=private%20state'] as $query) {
            $records = $this->get('/portal'.$query)
                ->assertOk()
                ->assertViewHas('portalStates', ['closed', 'new'])
                ->assertViewHas('portalState', fn ($state): bool => $state === null)
                ->viewData('records');

            $this->assertSame([$closed->id, $opened->id], $records->getCollection()->modelKeys());
            $this->assertSame(url('/portal').'?page=2', $records->url(2));
        }
    }

    public function test_request_id_lookup_is_exact_owned_and_combines_with_state(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $requests = $this->createCollection('requests');
        $matched = $this->createRecord($requests, $customer, 'closed');
        $this->createRecord($requests, $customer, 'closed');
        $this->createRecord($requests, $customer, 'new');
        $otherOwner = $this->createRecord($requests, $otherCustomer, 'closed');
        $otherCollection = $this->createRecord($this->createCollection('other'), $customer, 'closed');

        $this->actingAs($customer);
        $records = $this->get('/portal?state=closed&request_id=%2000'.$matched->id.'%20&unrelated=1')
            ->assertOk()
            ->assertViewHas('portalStates', ['closed', 'new'])
            ->assertViewHas('portalState', 'closed')
            ->assertViewHas('portalRequestId', '00'.$matched->id)
            ->assertViewHas('portalRequestIdInvalid', false)
            ->viewData('records');

        $this->assertSame([$matched->id], $records->getCollection()->modelKeys());
        $this->assertSame(url('/portal').'?state=closed&request_id='.$matched->id.'&page=2', $records->url(2));

        foreach ([
            'state=new&request_id='.$matched->id,
            'state=closed&request_id='.$otherOwner->id,
            'state=closed&request_id='.$otherCollection->id,
            'request_id='.PHP_INT_MAX,
        ] as $query) {
            $empty = $this->get('/portal?'.$query)->assertOk()
                ->assertViewHas('portalRequestIdInvalid', false)->viewData('records');
            $this->assertSame(0, $empty->total());
        }
    }

    public function test_invalid_request_ids_never_broaden_the_owned_history_lookup(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $requests = $this->createCollection('requests');
        $record = $this->createRecord($requests, $customer, 'new');
        $this->actingAs($customer);

        foreach (['0', '000', '-1', '%2B1', '1.0', '1e2', 'Some+title', PHP_INT_MAX.'0'] as $invalid) {
            $records = $this->get('/portal?state=new&request_id='.$invalid)->assertOk()
                ->assertViewHas('portalRequestId', urldecode($invalid))
                ->assertViewHas('portalRequestIdInvalid', true)->viewData('records');
            $this->assertSame(0, $records->total());
            $this->assertSame(url('/portal').'?state=new&page=2', $records->url(2));
        }

        $records = $this->get('/portal?request_id%5B%5D='.$record->id)->assertOk()
            ->assertViewHas('portalRequestId', '')
            ->assertViewHas('portalRequestIdInvalid', true)->viewData('records');
        $this->assertSame(0, $records->total());

        $records = $this->get('/portal?state=new&request_id=%20')->assertOk()
            ->assertViewHas('portalRequestId', '')
            ->assertViewHas('portalRequestIdInvalid', false)->viewData('records');
        $this->assertSame([$record->id], $records->getCollection()->modelKeys());
    }

    public function test_request_titles_are_read_from_matching_drafts_in_one_query(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $requests = $this->createCollection('requests');
        $requests->update(['definition' => ['fields' => [
            ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
        ]]]);
        $visible = $this->createRecord($requests, $customer, 'new');
        $second = $this->createRecord($requests, $customer, 'new');
        $this->setDraft($visible, ['title' => 'Visible title', 'internal' => 'Unprojected content']);
        $this->setDraft($second, ['title' => 'Second title']);
        $linkedToOtherOwner = $this->createRecord($requests, $customer, 'new');
        $foreign = $this->createRecord($requests, $otherCustomer, 'new');
        $linkedToOtherOwner->update(['draft_id' => $this->setDraft($foreign, ['title' => 'Other owner title'])->id]);
        $linkedToVisible = $this->createRecord($requests, $customer, 'new');
        $linkedToVisible->update(['draft_id' => $visible->draft_id]);
        $invalidTitle = $this->createRecord($requests, $customer, 'new');
        $this->setDraft($invalidTitle, ['title' => ['Invalid stored title']]);
        $this->createRecord($requests, $customer, 'new');

        DB::enableQueryLog();
        try {
            $response = $this->actingAs($customer)->get('/portal');
            $revisionQueries = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'formaku_revisions'));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertCount(1, $revisionQueries);
        $response->assertOk()->assertViewHas('portalRequestTitles', [
            $second->id => 'Second title',
            $visible->id => 'Visible title',
        ])->assertDontSee('Other owner title')->assertDontSee('Unprojected content')->assertDontSee('Invalid stored title');

        $this->get('/portal?request_id='.$linkedToVisible->id)->assertOk()->assertViewHas('portalRequestTitles', []);
        $this->get('/portal?request_id='.$visible->id)->assertOk()->assertViewHas('portalRequestTitles', [
            $visible->id => 'Visible title',
        ]);
    }

    public function test_request_titles_require_a_configured_non_private_readable_field(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $requests = $this->createCollection('requests');
        $record = $this->createRecord($requests, $customer, 'new');
        $this->setDraft($record, ['title' => 'Hidden title']);
        $title = ['key' => 'title', 'label' => 'Title', 'type' => 'text'];
        $this->actingAs($customer);
        foreach ([
            [],
            [$title + ['private' => true]],
            [$title + ['access' => ['read' => ['editor']]]],
        ] as $fields) {
            $requests->update(['definition' => ['fields' => $fields]]);
            $this->get('/portal?request_id='.$record->id)->assertOk()
                ->assertViewHas('portalRequestTitles', [])->assertDontSee('Hidden title');
        }
    }

    private function setDraft(Record $record, array $data): Revision
    {
        $draft = Revision::create(['record_id' => $record->id, 'schema_version' => 1, 'data' => $data]);
        $record->update(['draft_id' => $draft->id]);

        return $draft;
    }

    private function createCollection(string $key): Collection
    {
        return Collection::create([
            'key' => $key,
            'name' => ucfirst($key),
            'kind' => 'data',
            'definition' => ['fields' => [], 'states' => ['new']],
        ]);
    }

    private function createRecord(Collection $collection, User $owner, string $state): Record
    {
        return Record::create([
            'collection_id' => $collection->id,
            'owner_id' => $owner->id,
            'state' => $state,
        ]);
    }
}
