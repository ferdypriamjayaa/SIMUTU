<?php

namespace Tests\Concerns;

use App\Models\Indicator;
use App\Models\IndicatorField;
use App\Models\IndicatorFormVersion;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Drives the form builder through its HTTP routes, the way the admin UI does.
 */
trait BuildsIndicatorForms
{
    /**
     * The draft's stored fields in the shape the builder submits them.
     *
     * @return list<array<string, mixed>>
     */
    protected function builderFields(IndicatorFormVersion $version): array
    {
        return $version->fields()->with('options')->get()
            ->map(fn (IndicatorField $field) => [
                ...$field->only([
                    'id', 'key', 'label', 'type', 'section', 'placeholder', 'help_text', 'is_required', 'is_active',
                    'show_in_summary', 'default_value', 'min_value', 'max_value', 'allow_decimal', 'min_length',
                    'max_length', 'format', 'max_field_key', 'visible_when_key', 'visible_when_values',
                ]),
                'options' => $field->options->map->only(['value', 'label'])->all(),
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    protected function saveDraft(User $admin, IndicatorFormVersion $draft, array $fields, string $intent = 'save'): TestResponse
    {
        return $this->actingAs($admin)->put(
            route('form-builder.update', [$draft->indicator_id, $draft]),
            ['payload' => json_encode(['fields' => $fields]), 'intent' => $intent],
        );
    }

    protected function openDraft(User $admin, Indicator $indicator): IndicatorFormVersion
    {
        $this->actingAs($admin)->post(route('form-builder.draft.store', $indicator))->assertRedirect();

        return $indicator->draftFormVersion()->firstOrFail();
    }

    /**
     * Publish a new version of the indicator's form with extra fields appended.
     *
     * @param  list<array<string, mixed>>  $extraFields
     */
    protected function publishNewVersion(User $admin, Indicator $indicator, array $extraFields = [], ?callable $modify = null): IndicatorFormVersion
    {
        $draft = $this->openDraft($admin, $indicator);
        $fields = [...$this->builderFields($draft), ...$extraFields];

        $this->saveDraft($admin, $draft, $modify ? $modify($fields) : $fields, 'publish')
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('form-builder.show', $indicator));

        return $draft->fresh();
    }
}
