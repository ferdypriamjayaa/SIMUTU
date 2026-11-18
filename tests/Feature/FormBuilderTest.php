<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\IndicatorField;
use App\Models\IndicatorFormVersion;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use App\Support\ObservationForms;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsIndicatorForms;
use Tests\Concerns\CreatesInmIndicators;
use Tests\TestCase;

class FormBuilderTest extends TestCase
{
    use BuildsIndicatorForms, CreatesInmIndicators, RefreshDatabase;

    /** @var array<string, Indicator> */
    private array $indicators;

    private User $admin;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indicators = $this->createInmIndicators();
        $this->admin = User::factory()->admin()->create();
        $this->unit = Unit::create(['code' => 'IGD', 'name' => 'Instalasi Gawat Darurat', 'is_active' => true]);
        $this->assignIndicators($this->unit, $this->indicators);
    }

    /** An indicator that has never had a form. */
    private function blankIndicator(): Indicator
    {
        $category = IndicatorCategory::forceCreate(['code' => 'IMP_RS', 'slug' => 'imp-rs', 'name' => 'Indikator Mutu Pelayanan Rumah Sakit']);

        return Indicator::create(['indicator_category_id' => $category->id, 'name' => 'Indikator Baru', 'is_active' => true]);
    }

    private function ppe(): Indicator
    {
        return $this->indicators[ObservationForms::PPE];
    }

    // --- creating and editing drafts ---------------------------------------

    public function test_admin_can_create_an_indicator_form(): void
    {
        $indicator = $this->blankIndicator();

        $this->actingAs($this->admin)->get(route('form-builder.show', $indicator))
            ->assertOk()
            ->assertSee('Buat Formulir')
            ->assertSee('Indikator ini belum memiliki formulir.');

        $draft = $this->openDraft($this->admin, $indicator);

        $this->assertSame(1, $draft->version);
        $this->assertTrue($draft->isDraft());
        $this->assertSame($this->admin->id, $draft->created_by);
        $this->assertCount(0, $draft->fields);

        $this->actingAs($this->admin)->get(route('form-builder.edit', [$indicator, $draft]))
            ->assertOk()
            ->assertSee('Kanvas Formulir')
            ->assertSee('Properti Field');
    }

    public function test_indicator_list_links_to_the_form_builder_for_admin_only(): void
    {
        $this->actingAs($this->admin)->get(route('indicators.show', 'inm'))
            ->assertOk()
            ->assertSee(route('form-builder.show', $this->ppe()));

        $this->actingAs(User::factory()->picMutu()->create())->get(route('indicators.show', 'inm'))
            ->assertOk()
            ->assertDontSee(route('form-builder.show', $this->ppe()));
    }

    public function test_admin_can_add_and_edit_fields(): void
    {
        $draft = $this->openDraft($this->admin, $this->blankIndicator());

        $this->saveDraft($this->admin, $draft, [
            ['label' => 'Nama Petugas', 'type' => 'text', 'is_required' => true, 'placeholder' => 'Nama lengkap', 'help_text' => 'Petugas yang diobservasi', 'max_length' => 100],
            ['label' => 'Jumlah Pasien', 'type' => 'number', 'key' => 'patient_count', 'min_value' => 0, 'max_value' => 50, 'is_required' => false],
            ['label' => 'Shift', 'type' => 'select', 'options' => [['label' => 'Pagi'], ['label' => 'Malam', 'value' => 'night']], 'default_value' => 'night'],
        ])->assertSessionHasNoErrors()->assertRedirect(route('form-builder.edit', [$draft->indicator_id, $draft]));

        $fields = $draft->fields()->with('options')->get()->keyBy('key');

        // Keys are generated from the label when not given.
        $this->assertSame(['nama_petugas', 'patient_count', 'shift'], $fields->keys()->all());
        $this->assertSame('Nama lengkap', $fields['nama_petugas']->placeholder);
        $this->assertSame('Petugas yang diobservasi', $fields['nama_petugas']->help_text);
        $this->assertSame(100, $fields['nama_petugas']->max_length);
        $this->assertTrue($fields['nama_petugas']->is_required);
        $this->assertFalse($fields['patient_count']->is_required);
        $this->assertSame(50.0, $fields['patient_count']->max_value);
        $this->assertSame(['pagi' => 'Pagi', 'night' => 'Malam'], $fields['shift']->options->pluck('label', 'value')->all());
        $this->assertSame('night', $fields['shift']->default_value);

        // Editing keeps the same row and applies the changes.
        $submitted = $this->builderFields($draft);
        $submitted[0]['label'] = 'Nama Petugas Jaga';
        $submitted[0]['is_active'] = false;
        $submitted[1]['key'] = 'total_patients';

        $this->saveDraft($this->admin, $draft, $submitted)->assertSessionHasNoErrors();

        $edited = IndicatorField::find($fields['nama_petugas']->id);
        $this->assertSame('Nama Petugas Jaga', $edited->label);
        $this->assertFalse($edited->is_active);
        $this->assertSame('total_patients', IndicatorField::find($fields['patient_count']->id)->key);
        $this->assertSame(3, $draft->fields()->count());
    }

    public function test_admin_can_remove_and_swap_keys_of_draft_fields(): void
    {
        $draft = $this->openDraft($this->admin, $this->blankIndicator());
        $this->saveDraft($this->admin, $draft, [
            ['label' => 'A', 'type' => 'text', 'key' => 'first'],
            ['label' => 'B', 'type' => 'text', 'key' => 'second'],
            ['label' => 'C', 'type' => 'text', 'key' => 'third'],
        ])->assertSessionHasNoErrors();

        [$a, $b] = $this->builderFields($draft);
        $a['key'] = 'second';
        $b['key'] = 'first';

        $this->saveDraft($this->admin, $draft, [$a, $b])->assertSessionHasNoErrors();

        $this->assertSame(['second' => 'A', 'first' => 'B'], $draft->fields()->pluck('label', 'key')->all());
    }

    public function test_admin_can_reorder_fields(): void
    {
        $draft = $this->openDraft($this->admin, $this->ppe());
        $original = $this->builderFields($draft);

        $this->saveDraft($this->admin, $draft, array_reverse($original))->assertSessionHasNoErrors();

        $this->assertSame(
            array_reverse(array_column($original, 'key')),
            $draft->fields()->pluck('key')->all(),
        );
    }

    public function test_invalid_field_definitions_are_rejected(): void
    {
        $draft = $this->openDraft($this->admin, $this->blankIndicator());

        $this->saveDraft($this->admin, $draft, [
            ['label' => '', 'type' => 'text'],
            ['label' => 'Tanggal', 'type' => 'text', 'key' => 'observation_date'],
            ['label' => 'Pilihan', 'type' => 'radio', 'options' => []],
            ['label' => 'Tipe aneh', 'type' => 'file'],
            ['label' => 'Rentang', 'type' => 'number', 'min_value' => 10, 'max_value' => 1],
            ['label' => 'Kunci buruk', 'type' => 'text', 'key' => 'Nama Petugas'],
        ])->assertSessionHasErrors(['fields.0.label', 'fields.3.type', 'fields.5.key']);

        $this->saveDraft($this->admin, $draft, [
            ['label' => 'Tanggal', 'type' => 'text', 'key' => 'observation_date'],
            ['label' => 'Pilihan', 'type' => 'radio', 'options' => []],
            ['label' => 'Rentang', 'type' => 'number', 'min_value' => 10, 'max_value' => 1],
            ['label' => 'Kembar 1', 'type' => 'text', 'key' => 'same'],
            ['label' => 'Kembar 2', 'type' => 'text', 'key' => 'same'],
            ['label' => 'Bawaan', 'type' => 'yes_no', 'default_value' => 'mungkin'],
        ])->assertSessionHasErrors(['fields.0.key', 'fields.1.options', 'fields.2.max_value', 'fields.3.key', 'fields.5.default_value']);

        $this->saveDraft($this->admin, $draft, [])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('form-builder.update', [$draft->indicator_id, $draft]), ['payload' => 'bukan json'])
            ->assertSessionHasErrors('fields');

        $this->assertSame(0, $draft->fields()->count());
    }

    public function test_admin_can_save_drafts_without_affecting_the_published_form(): void
    {
        $indicator = $this->ppe();
        $published = $indicator->publishedFormVersion;
        $draft = $this->openDraft($this->admin, $indicator);

        $this->saveDraft($this->admin, $draft, [
            ...$this->builderFields($draft),
            ['label' => 'Field Draf', 'type' => 'text'],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertTrue($indicator->publishedFormVersion()->first()->is($published));

        // PIC Unit still gets the published form.
        $picUnit = User::factory()->picUnit($this->unit)->create();
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $indicator))
            ->assertOk()
            ->assertDontSee('Field Draf');

        // Admin can preview the draft.
        $this->actingAs($this->admin)->get(route('form-builder.preview', [$indicator, $draft]))
            ->assertOk()
            ->assertSee('Field Draf')
            ->assertSee('name="field_draf"', false);
    }

    public function test_admin_can_discard_a_draft_but_not_a_published_version(): void
    {
        $indicator = $this->ppe();
        $published = $indicator->publishedFormVersion;
        $draft = $this->openDraft($this->admin, $indicator);

        $this->actingAs($this->admin)->delete(route('form-builder.destroy', [$indicator, $draft]))
            ->assertRedirect(route('form-builder.show', $indicator));
        $this->assertModelMissing($draft);

        $this->actingAs($this->admin)->delete(route('form-builder.destroy', [$indicator, $published]))->assertStatus(409);
        $this->assertModelExists($published);
    }

    // --- publishing and versioning ------------------------------------------

    public function test_admin_can_publish_forms(): void
    {
        $indicator = $this->blankIndicator();
        $draft = $this->openDraft($this->admin, $indicator);

        // An empty form cannot be published.
        $this->actingAs($this->admin)->post(route('form-builder.publish', [$indicator, $draft]))->assertSessionHasErrors('form');
        $this->assertTrue($draft->fresh()->isDraft());

        $this->saveDraft($this->admin, $draft, [['label' => 'Catatan', 'type' => 'textarea']], 'publish')
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('form-builder.show', $indicator));

        $draft->refresh();
        $this->assertTrue($draft->isPublished());
        $this->assertNotNull($draft->published_at);
        $this->assertSame($this->admin->id, $draft->published_by);
        $this->assertTrue($indicator->publishedFormVersion()->first()->is($draft));
    }

    public function test_publishing_archives_the_previous_version(): void
    {
        $indicator = $this->ppe();
        $first = $indicator->publishedFormVersion;

        $second = $this->publishNewVersion($this->admin, $indicator, [['label' => 'Lokasi', 'type' => 'text']]);

        $this->assertSame(2, $second->version);
        $this->assertTrue($second->isPublished());
        $this->assertSame(IndicatorFormVersion::STATUS_ARCHIVED, $first->fresh()->status);
        $this->assertNotNull($first->fresh()->archived_at);
        $this->assertSame(1, $indicator->formVersions()->where('status', IndicatorFormVersion::STATUS_PUBLISHED)->count());

        // Archived versions stay readable.
        $this->actingAs($this->admin)->get(route('form-builder.preview', [$indicator, $first]))
            ->assertOk()
            ->assertSee('Arsip')
            ->assertDontSee('name="lokasi"', false);
    }

    public function test_database_allows_only_one_published_and_one_draft_version_per_indicator(): void
    {
        $indicator = $this->ppe();

        $indicator->formVersions()->create(['version' => 2, 'status' => IndicatorFormVersion::STATUS_DRAFT]);

        try {
            $indicator->formVersions()->create(['version' => 3, 'status' => IndicatorFormVersion::STATUS_DRAFT]);
            $this->fail('A second draft was accepted.');
        } catch (QueryException) {
        }

        $this->expectException(QueryException::class);
        $indicator->formVersions()->create(['version' => 4, 'status' => IndicatorFormVersion::STATUS_PUBLISHED]);
    }

    public function test_published_forms_cannot_be_modified_directly(): void
    {
        $indicator = $this->ppe();
        $published = $indicator->publishedFormVersion;
        $before = $this->builderFields($published);

        $changed = $before;
        $changed[0]['label'] = 'Diubah Langsung';

        $this->saveDraft($this->admin, $published, $changed)->assertStatus(409);
        $this->saveDraft($this->admin, $published, [])->assertStatus(409);
        $this->actingAs($this->admin)->post(route('form-builder.publish', [$indicator, $published]))->assertStatus(409);

        $this->assertSame($before, $this->builderFields($published));

        // The builder never opens a published version.
        $this->actingAs($this->admin)->get(route('form-builder.edit', [$indicator, $published]))
            ->assertRedirect(route('form-builder.show', $indicator))
            ->assertSessionHas('error');
    }

    public function test_editing_a_published_form_creates_a_new_version(): void
    {
        $indicator = $this->ppe();
        $published = $indicator->publishedFormVersion;

        $this->actingAs($this->admin)->get(route('form-builder.show', $indicator))->assertOk()->assertSee('Edit Formulir');

        $draft = $this->openDraft($this->admin, $indicator);

        $this->assertFalse($draft->is($published));
        $this->assertSame(2, $draft->version);
        $this->assertTrue($draft->isDraft());
        $this->assertTrue($published->fresh()->isPublished());

        // The draft starts as a copy: same keys, new rows.
        $this->assertSame($published->fields()->pluck('key')->all(), $draft->fields()->pluck('key')->all());
        $this->assertEmpty(array_intersect($published->fields()->pluck('id')->all(), $draft->fields()->pluck('id')->all()));

        // Opening the builder again continues the same draft.
        $this->assertTrue($this->openDraft($this->admin, $indicator)->is($draft));
        $this->assertSame(2, $indicator->formVersions()->count());
    }

    public function test_version_of_another_indicator_is_not_found(): void
    {
        $other = $this->indicators[ObservationForms::IDENTIFICATION];
        $draft = $this->openDraft($this->admin, $this->ppe());

        $this->actingAs($this->admin)->get(route('form-builder.edit', [$other, $draft]))->assertNotFound();
        $this->actingAs($this->admin)->get(route('form-builder.preview', [$other, $draft]))->assertNotFound();
        $this->actingAs($this->admin)->put(route('form-builder.update', [$other, $draft]), ['payload' => '{"fields":[]}'])->assertNotFound();
        $this->actingAs($this->admin)->delete(route('form-builder.destroy', [$other, $draft]))->assertNotFound();
    }

    // --- fields with recorded data --------------------------------------------

    public function test_fields_with_recorded_data_cannot_be_removed_rekeyed_or_retyped(): void
    {
        $indicator = $this->ppe();
        $picUnit = User::factory()->picUnit($this->unit)->create();

        $this->actingAs($picUnit)->post(route('observations.store'), [
            'indicator_id' => $indicator->id,
            'observation_date' => now()->toDateString(),
            ...ObservationTest::validSubmissions()['apd'][1],
        ])->assertSessionHasNoErrors();

        $draft = $this->openDraft($this->admin, $indicator);
        $fields = $this->builderFields($draft);
        $maskIndex = array_search('mask', array_column($fields, 'key'), true);

        // Removing it.
        $without = $fields;
        unset($without[$maskIndex]);
        $this->saveDraft($this->admin, $draft, array_values($without))->assertSessionHasErrors('fields');

        // Renaming its key.
        $rekeyed = $fields;
        $rekeyed[$maskIndex]['key'] = 'masker';
        $this->saveDraft($this->admin, $draft, $rekeyed)->assertSessionHasErrors('fields');

        // Changing its type.
        $retyped = $fields;
        $retyped[$maskIndex]['type'] = 'text';
        $this->saveDraft($this->admin, $draft, $retyped)->assertSessionHasErrors("fields.{$maskIndex}.type");

        $this->assertSame(array_column($fields, 'key'), $draft->fields()->pluck('key')->all());

        // Deactivating and relabelling is allowed.
        $fields[$maskIndex]['is_active'] = false;
        $fields[$maskIndex]['label'] = 'Masker Bedah';
        $this->saveDraft($this->admin, $draft, $fields)->assertSessionHasNoErrors();
        $this->assertFalse($draft->fields()->where('key', 'mask')->sole()->is_active);
    }

    public function test_fields_used_by_records_from_before_form_versions_are_protected_too(): void
    {
        $indicator = $this->indicators[ObservationForms::IDENTIFICATION];
        Observation::create([
            'indicator_id' => $indicator->id,
            'unit_id' => $this->unit->id,
            'user_id' => $this->admin->id,
            'observation_date' => now()->toDateString(),
            'officer_name' => 'Petugas Lama',
            'patient_name' => 'Pasien Lama',
        ]);

        $draft = $this->openDraft($this->admin, $indicator);
        $fields = array_values(array_filter($this->builderFields($draft), fn ($field) => $field['key'] !== 'patient_name'));

        $this->saveDraft($this->admin, $draft, $fields)->assertSessionHasErrors('fields');
    }

    public function test_database_refuses_to_delete_a_field_that_has_answers(): void
    {
        $indicator = $this->ppe();
        $picUnit = User::factory()->picUnit($this->unit)->create();

        $this->actingAs($picUnit)->post(route('observations.store'), [
            'indicator_id' => $indicator->id,
            'observation_date' => now()->toDateString(),
            ...ObservationTest::validSubmissions()['apd'][1],
        ])->assertSessionHasNoErrors();

        $this->expectException(QueryException::class);
        $indicator->publishedFormVersion->fields()->where('key', 'mask')->sole()->delete();
    }

    // --- authorization -------------------------------------------------------

    public static function nonAdminRoles(): array
    {
        return ['pic mutu' => [User::ROLE_PIC_MUTU], 'pic unit' => [User::ROLE_PIC_UNIT]];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_roles_cannot_access_the_form_builder(string $role): void
    {
        $indicator = $this->ppe();
        $published = $indicator->publishedFormVersion;
        $draft = $this->openDraft($this->admin, $indicator);
        $user = User::factory()->create(['role' => $role, 'unit_id' => $this->unit->id]);

        $this->actingAs($user);

        $this->get(route('form-builder.show', $indicator))->assertForbidden();
        $this->post(route('form-builder.draft.store', $indicator))->assertForbidden();
        $this->get(route('form-builder.edit', [$indicator, $draft]))->assertForbidden();
        $this->get(route('form-builder.preview', [$indicator, $draft]))->assertForbidden();
        $this->get(route('form-builder.preview', [$indicator, $published]))->assertForbidden();
        $this->put(route('form-builder.update', [$indicator, $draft]), ['payload' => '{"fields":[]}'])->assertForbidden();
        $this->post(route('form-builder.publish', [$indicator, $draft]))->assertForbidden();
        $this->delete(route('form-builder.destroy', [$indicator, $draft]))->assertForbidden();

        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertSame($published->fields()->count(), $draft->fields()->count());
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('form-builder.show', $this->ppe()))->assertRedirect(route('login'));
        $this->post(route('form-builder.draft.store', $this->ppe()))->assertRedirect(route('login'));
    }
}
