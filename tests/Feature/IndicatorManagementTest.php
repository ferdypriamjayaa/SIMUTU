<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndicatorManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            ['INM', 'inm', 'Indikator Nasional Mutu'],
            ['IMP_RS', 'imp-rs', 'Indikator Mutu Pelayanan Rumah Sakit'],
            ['IMP_UNIT', 'imp-unit', 'Indikator Mutu Pelayanan Unit'],
        ] as [$code, $slug, $name]) {
            IndicatorCategory::forceCreate(['code' => $code, 'slug' => $slug, 'name' => $name]);
        }
    }

    public function test_indicator_page_shows_full_category_name_without_code(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('indicators.show', 'imp-rs'))
            ->assertOk()
            ->assertSee('Indikator Mutu Pelayanan Rumah Sakit')
            ->assertDontSee('IMP_RS');
    }

    public static function impCategories(): array
    {
        return ['imp rs' => ['imp-rs'], 'imp unit' => ['imp-unit']];
    }

    #[DataProvider('impCategories')]
    public function test_admin_can_add_imp_indicator(string $slug): void
    {
        $admin = User::factory()->admin()->create();
        $unit = Unit::create(['code' => 'IGD', 'name' => 'IGD', 'is_active' => true]);

        $this->actingAs($admin)->get(route('indicators.show', $slug))
            ->assertSee(route('indicators.create', $slug))
            ->assertSee('Tambah Indikator');

        $this->actingAs($admin)->get(route('indicators.create', $slug))->assertOk();

        $this->actingAs($admin)->post(route('indicators.store', $slug), [
            'name' => 'Indikator Baru',
            'unit_id' => $unit->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('indicators.show', $slug));

        $indicator = Indicator::where('name', 'Indikator Baru')->sole();
        $this->assertSame(IndicatorCategory::where('slug', $slug)->value('id'), $indicator->indicator_category_id);
        $this->assertSame($slug === 'imp-unit' ? $unit->id : null, $indicator->unit_id);
    }

    public function test_inm_page_has_no_add_button(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('indicators.show', 'inm'))
            ->assertDontSee('Tambah Indikator');
    }

    public function test_pic_mutu_cannot_add_indicator(): void
    {
        $picMutu = User::factory()->picMutu()->create();

        $this->actingAs($picMutu)->get(route('indicators.show', 'imp-rs'))
            ->assertOk()
            ->assertDontSee('Tambah Indikator');
        $this->actingAs($picMutu)->post(route('indicators.store', 'imp-rs'), ['name' => 'X'])->assertForbidden();
    }
}
