<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const BOOTSTRAP = '/formaku/api/v1/bootstrap';

    public function test_only_builders_receive_the_starter_role_catalog(): void
    {
        $this->getJson(self::BOOTSTRAP)->assertUnauthorized()->assertJsonMissingPath('roles');

        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->getJson(self::BOOTSTRAP)
            ->assertOk()
            ->assertJsonMissingPath('roles');

        $builder = User::factory()->create(['role' => 'admin']);
        $this->actingAs($builder)->getJson(self::BOOTSTRAP)
            ->assertOk()
            ->assertJsonPath('roles', [
                ['key' => 'admin', 'label' => 'Administrator'],
                ['key' => 'editor', 'label' => 'Redaktor'],
                ['key' => 'publisher', 'label' => 'Publikujący'],
                ['key' => 'customer', 'label' => 'Klient'],
            ]);
    }

    public function test_builder_role_catalog_only_contains_valid_configured_entries(): void
    {
        $builder = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'unlisted']);
        config(['formaku.roles' => [
            'regional-review.lead' => ' Review lead ',
            42 => 'Custom numeric role',
            ' blank-key ' => 'Unusable key',
            ' ' => 'Whitespace-only key',
            '' => 'Empty key',
            'blank-label' => ' ',
            'non-string-label' => ['Label'],
        ]]);

        $this->actingAs($builder)->getJson(self::BOOTSTRAP)
            ->assertOk()
            ->assertJsonPath('roles', [
                ['key' => 'regional-review.lead', 'label' => 'Review lead'],
                ['key' => '42', 'label' => 'Custom numeric role'],
            ]);

        config(['formaku.roles' => 'malformed']);
        $this->getJson(self::BOOTSTRAP)->assertOk()->assertJsonPath('roles', []);

        config(['formaku.roles' => null]);
        $this->getJson(self::BOOTSTRAP)->assertOk()->assertJsonPath('roles', []);
    }
}
