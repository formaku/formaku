<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
