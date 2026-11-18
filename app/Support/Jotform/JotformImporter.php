<?php

namespace App\Support\Jotform;

use App\Models\Indicator;
use App\Models\IndicatorField;
use App\Models\IndicatorFormVersion;
use App\Models\JotformForm;
use App\Models\User;
use App\Support\FormBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports a Jotform definition as a DRAFT form version of its mapped
 * indicator. Nothing is published and no existing version is touched: the
 * Admin reviews the draft in the form builder and publishes it there.
 *
 * Importing is repeatable. The same definition imported twice changes
 * nothing; a changed definition refreshes the draft the previous import
 * made, or starts a new draft version if that one was already published.
 */
class JotformImporter
{
    public const IMPORTED = 'imported';

    public const UNCHANGED = 'unchanged';

    /**
     * Record which indicator a Jotform form belongs to. Refused once the
     * form has been imported, and when either side is already paired.
     *
     * @throws JotformImportException
     */
    public static function map(JotformDefinition $definition, Indicator $indicator): JotformForm
    {
        return DB::transaction(function () use ($definition, $indicator) {
            $record = JotformForm::where('jotform_form_id', $definition->formId)->lockForUpdate()->first();

            if ($record?->indicator_form_version_id && $record->indicator_id !== $indicator->id) {
                throw new JotformImportException('Formulir ini sudah diimpor. Hapus draf hasil impor terlebih dahulu bila pemetaan perlu diubah.');
            }

            $other = JotformForm::where('indicator_id', $indicator->id)
                ->where('jotform_form_id', '!=', $definition->formId)
                ->first();

            if ($other) {
                throw new JotformImportException("Indikator \"{$indicator->name}\" sudah dipetakan ke formulir Jotform lain ({$other->title}).");
            }

            return JotformForm::updateOrCreate(
                ['jotform_form_id' => $definition->formId],
                ['title' => $definition->title, 'source_directory' => $definition->directory, 'indicator_id' => $indicator->id],
            );
        });
    }

    /**
     * @return array{status: string, version: IndicatorFormVersion}
     *
     * @throws JotformImportException
     */
    public static function import(JotformDefinition $definition, User $user): array
    {
        if (! $definition->isImportable()) {
            throw new JotformImportException('Definisi tidak dapat diimpor: '.implode(' ', $definition->errors));
        }

        return DB::transaction(function () use ($definition, $user) {
            $record = JotformForm::where('jotform_form_id', $definition->formId)->lockForUpdate()->first();
            $indicator = $record?->indicator_id ? Indicator::whereKey($record->indicator_id)->lockForUpdate()->first() : null;

            if (! $indicator) {
                throw new JotformImportException('Indikator SIMUTU untuk formulir ini belum dikonfirmasi.');
            }

            $previous = $record->indicator_form_version_id
                ? IndicatorFormVersion::whereKey($record->indicator_form_version_id)->lockForUpdate()->first()
                : null;

            if ($previous && $previous->indicator_id !== $indicator->id) {
                throw new JotformImportException('Formulir ini sudah diimpor ke indikator lain.');
            }

            // Same definition, and its version still exists: nothing to do.
            if ($previous && $record->definition_hash === $definition->hash) {
                return ['status' => self::UNCHANGED, 'version' => $previous];
            }

            $draft = $indicator->draftFormVersion()->lockForUpdate()->first();

            if ($draft && ! $draft->is($previous)) {
                throw new JotformImportException("Indikator \"{$indicator->name}\" memiliki draf formulir (versi {$draft->version}) yang bukan hasil impor ini. Publikasikan atau hapus draf tersebut terlebih dahulu.");
            }

            // Keys already used for a question stay the same in later versions.
            $reference = $draft ?? $indicator->formVersions()->first();
            $knownKeys = $reference
                ? $reference->fields()->whereNotNull('external_id')->pluck('key', 'external_id')->all()
                : [];

            $locked = FormBuilder::lockedFields($indicator);

            if ($draft) {
                $draft->fields()->get()->each->delete();
            } else {
                $draft = $indicator->formVersions()->create([
                    'version' => ((int) $indicator->formVersions()->max('version')) + 1,
                    'status' => IndicatorFormVersion::STATUS_DRAFT,
                    'created_by' => $user->id,
                ]);
            }

            $taken = [...IndicatorField::RESERVED_KEYS, ...array_keys($locked)];
            $order = 0;

            foreach ($definition->fields as $field) {
                $key = $knownKeys[$field['external_id']] ?? null;

                if ($key === null || in_array($key, $taken, true) && ($locked[$key] ?? null) !== $field['type']) {
                    $key = self::uniqueKey($field['label'], $taken);
                }

                $taken[] = $key;

                $model = $draft->fields()->create([
                    ...collect($field)->except('options')->all(),
                    'key' => $key,
                    'is_active' => true,
                    'sort_order' => $order++,
                ]);

                foreach ($field['options'] as $position => $option) {
                    $model->options()->create([...$option, 'sort_order' => $position]);
                }
            }

            // Fields that recorded observations depend on are never dropped:
            // those the Jotform form does not have are carried over, switched off.
            $present = $draft->fields()->pluck('key')->all();
            $published = $indicator->publishedFormVersion()->with('fields.options')->first();

            foreach ($published?->fields ?? [] as $field) {
                if (! isset($locked[$field->key]) || in_array($field->key, $present, true)) {
                    continue;
                }

                $copy = $draft->fields()->create([
                    ...$field->only([
                        'key', 'label', 'type', 'section', 'placeholder', 'help_text', 'is_required', 'show_in_summary',
                        'default_value', 'min_value', 'max_value', 'allow_decimal', 'min_length', 'max_length', 'format',
                        'max_field_key', 'visible_when_key', 'visible_when_values', 'external_id',
                    ]),
                    'is_active' => false,
                    'sort_order' => $order++,
                ]);

                foreach ($field->options as $option) {
                    $copy->options()->create($option->only(['value', 'label', 'sort_order']));
                }
            }

            $record->update([
                'title' => $definition->title,
                'source_directory' => $definition->directory,
                'indicator_form_version_id' => $draft->id,
                'definition_hash' => $definition->hash,
                'question_count' => count($definition->fields),
                'imported_at' => now(),
                'imported_by' => $user->id,
            ]);

            $draft->touch();

            return ['status' => self::IMPORTED, 'version' => $draft];
        });
    }

    /**
     * @param  list<string>  $taken
     */
    private static function uniqueKey(string $label, array $taken): string
    {
        $base = Str::slug($label, '_');
        $base = preg_match('/^[a-z]/', $base) ? $base : trim('field_'.$base, '_');
        $base = rtrim(substr($base, 0, 56), '_');

        $candidate = $base;

        for ($suffix = 2; in_array($candidate, $taken, true); $suffix++) {
            $candidate = $base.'_'.$suffix;
        }

        return $candidate;
    }
}
