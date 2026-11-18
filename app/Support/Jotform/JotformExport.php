<?php

namespace App\Support\Jotform;

use App\Models\Indicator;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The exported Jotform definitions on disk: one sub-directory per form,
 * each holding form.json and questions.json.
 */
class JotformExport
{
    /** Share (%) of an indicator's distinctive words a form title must contain to be hinted at. */
    private const SUGGESTION_THRESHOLD = 60.0;

    /**
     * Words nearly every quality indicator shares; they say nothing about
     * whether two titles are about the same thing.
     */
    private const GENERIC_WORDS = [
        'kepatuhan', 'kelengkapan', 'pelaksanaan', 'pengisian', 'upaya', 'form', 'formulir', 'spo',
        'pasien', 'petugas', 'dokter', 'spesialis', 'perawat', 'bidan',
        'dan', 'di', 'oleh', 'pada', 'dalam', 'bagi', 'setiap', 'terhadap', 'sebelum', 'sesudah', 'yang', 'untuk',
    ];

    public static function path(): string
    {
        return (string) config('services.jotform.export_path', base_path('JOTFORM-EXPORT'));
    }

    /**
     * Every export directory, parsed. A directory that cannot be read is
     * listed with its error instead of stopping the others.
     *
     * @return list<array{directory: string, definition: ?JotformDefinition, error: ?string}>
     */
    public static function all(): array
    {
        $path = self::path();

        if (! is_dir($path)) {
            return [];
        }

        $directories = array_filter(glob($path.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: []);
        sort($directories, SORT_NATURAL);

        $entries = [];
        $seen = [];

        foreach ($directories as $directory) {
            $entry = ['directory' => basename($directory), 'definition' => null, 'error' => null];

            try {
                $definition = JotformDefinition::fromDirectory($directory);

                if (isset($seen[$definition->formId])) {
                    throw new RuntimeException("Form ID {$definition->formId} sudah ada di folder {$seen[$definition->formId]}.");
                }

                $seen[$definition->formId] = $entry['directory'];
                $entry['definition'] = $definition;
            } catch (RuntimeException $exception) {
                $entry['error'] = $exception->getMessage();
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    public static function find(string $formId): ?JotformDefinition
    {
        foreach (self::all() as $entry) {
            if ($entry['definition']?->formId === $formId) {
                return $entry['definition'];
            }
        }

        return null;
    }

    /**
     * The indicator a form most likely belongs to, judged by its title.
     *
     * Only an exact match (ignoring case, spacing and punctuation) is ever
     * applied automatically. Anything else is a hint for the Admin: the
     * indicator whose distinctive words mostly appear in the form title.
     * A related title is often a different indicator, so a hint is never
     * preselected.
     *
     * @param  Collection<int, Indicator>  $indicators
     * @return array{indicator: Indicator, exact: bool, score: float}|null
     */
    public static function suggest(JotformDefinition $definition, Collection $indicators): ?array
    {
        $title = self::normalize($definition->title);
        $titleWords = self::distinctiveWords($title);
        $best = null;

        foreach ($indicators as $indicator) {
            $name = self::normalize($indicator->name);

            if ($name === $title) {
                return ['indicator' => $indicator, 'exact' => true, 'score' => 100.0];
            }

            $words = self::distinctiveWords($name);

            if ($words === []) {
                continue;
            }

            $score = count(array_intersect($words, $titleWords)) / count($words) * 100;

            if ($score >= self::SUGGESTION_THRESHOLD && $score > ($best['score'] ?? 0)) {
                $best = ['indicator' => $indicator, 'exact' => false, 'score' => round($score, 1)];
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    private static function distinctiveWords(string $normalized): array
    {
        return array_values(array_unique(array_filter(
            explode(' ', $normalized),
            fn (string $word) => mb_strlen($word) > 2 && ! in_array($word, self::GENERIC_WORDS, true),
        )));
    }

    private static function normalize(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($text)));
    }
}
