<?php

namespace App\Support;

use App\Models\Indicator;
use App\Models\IndicatorField;
use App\Models\IndicatorFormVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draft, save and publish indicator form versions.
 *
 * Only drafts are ever written to. Publishing archives the previous published
 * version in the same transaction, so an indicator always has at most one
 * published form and older versions stay intact for their observations.
 */
class FormBuilder
{
    /**
     * Field keys of this indicator that recorded observations depend on,
     * mapped to their field type. Such a field keeps its key and type in
     * every later version and can be deactivated but not removed.
     *
     * @return array<string, string> key => type
     */
    public static function lockedFields(Indicator $indicator): array
    {
        $versionIds = $indicator->formVersions()->pluck('id');

        $locked = IndicatorField::whereIn('indicator_form_version_id', $versionIds)
            ->whereHas('values')
            ->pluck('type', 'key')
            ->all();

        // Records from before form versions hold answers for the first form.
        if ($indicator->observations()->whereNull('indicator_form_version_id')->exists()) {
            $baseline = $indicator->baselineFormVersion()->with('fields')->first();

            foreach ($baseline?->activeFields() ?? [] as $field) {
                $locked[$field->key] ??= $field->type;
            }
        }

        return $locked;
    }

    /**
     * The indicator's draft: the existing one, or a new version copied from
     * the published form (or the latest version, or empty for a first form).
     */
    public static function draft(Indicator $indicator, User $user): IndicatorFormVersion
    {
        return DB::transaction(function () use ($indicator, $user) {
            // Serialises concurrent requests for the same indicator.
            Indicator::whereKey($indicator->id)->lockForUpdate()->first();

            if ($draft = $indicator->draftFormVersion()->first()) {
                return $draft;
            }

            $source = $indicator->publishedFormVersion()->with('fields.options')->first()
                ?? $indicator->formVersions()->with('fields.options')->first();

            $draft = $indicator->formVersions()->create([
                'version' => ((int) $indicator->formVersions()->max('version')) + 1,
                'status' => IndicatorFormVersion::STATUS_DRAFT,
                'created_by' => $user->id,
            ]);

            foreach ($source?->fields ?? [] as $field) {
                $copy = $draft->fields()->create($field->only([
                    'key', 'label', 'type', 'section', 'placeholder', 'help_text', 'is_required', 'is_active',
                    'show_in_summary', 'sort_order', 'default_value', 'min_value', 'max_value', 'allow_decimal',
                    'min_length', 'max_length', 'format', 'max_field_key', 'visible_when_key', 'visible_when_values', 'external_id',
                ]));

                foreach ($field->options as $option) {
                    $copy->options()->create($option->only(['value', 'label', 'sort_order']));
                }
            }

            return $draft;
        });
    }

    /**
     * Replace the draft's fields with the validated definition
     * (see SaveFormDraftRequest::fields()), in the given order.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    public static function save(IndicatorFormVersion $draft, array $fields): void
    {
        DB::transaction(function () use ($draft, $fields) {
            $draft = self::lockDraft($draft);
            $existing = $draft->fields()->get()->keyBy('id');

            // Free every key that is about to change first, so renames and
            // swaps never collide with the unique (version, key) index.
            foreach ($fields as $field) {
                $current = $existing->get($field['id']);

                if ($current && $current->key !== $field['key']) {
                    $current->update(['key' => '__'.$current->id]);
                }
            }

            $kept = [];

            foreach ($fields as $order => $field) {
                $attributes = [...collect($field)->except(['id', 'options'])->all(), 'sort_order' => $order];
                $model = $existing->get($field['id']);

                if ($model) {
                    $model->update($attributes);
                } else {
                    $model = $draft->fields()->create($attributes);
                }

                $kept[] = $model->id;

                // Draft fields never have answers, so options can be rebuilt.
                $model->options()->delete();

                foreach ($field['options'] as $position => $option) {
                    $model->options()->create([...$option, 'sort_order' => $position]);
                }
            }

            $draft->fields()->whereNotIn('id', $kept)->get()->each->delete();
            $draft->touch();
        });
    }

    /**
     * Make the draft the indicator's published form.
     */
    public static function publish(IndicatorFormVersion $draft, User $user): void
    {
        DB::transaction(function () use ($draft, $user) {
            Indicator::whereKey($draft->indicator_id)->lockForUpdate()->first();
            $draft = self::lockDraft($draft);

            if (! $draft->fields()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'form' => 'Formulir belum dapat dipublikasikan: tambahkan minimal satu field yang aktif.',
                ]);
            }

            // Archive first: the database allows one published version per indicator.
            IndicatorFormVersion::where('indicator_id', $draft->indicator_id)
                ->where('status', IndicatorFormVersion::STATUS_PUBLISHED)
                ->update(['status' => IndicatorFormVersion::STATUS_ARCHIVED, 'archived_at' => now()]);

            $draft->update([
                'status' => IndicatorFormVersion::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $user->id,
            ]);
        });
    }

    /**
     * Throw the draft away. Published and archived versions are never deleted.
     */
    public static function discard(IndicatorFormVersion $draft): void
    {
        DB::transaction(fn () => self::lockDraft($draft)->delete());
    }

    /**
     * Re-read the version under a row lock and make sure it is still a draft.
     */
    private static function lockDraft(IndicatorFormVersion $version): IndicatorFormVersion
    {
        $locked = IndicatorFormVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();

        abort_unless($locked->isDraft(), 409, 'Versi formulir ini sudah dipublikasikan dan tidak dapat diubah. Buat draf baru untuk mengubah formulir.');

        return $locked;
    }
}
