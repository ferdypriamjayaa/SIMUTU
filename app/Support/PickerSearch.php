<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Name search and paging for the searchable dropdowns of the observation
 * form (HRIS employees, Doctor API doctors). The lists themselves come from
 * the cached services; only one page of matches is sent to the browser.
 */
class PickerSearch
{
    public const PER_PAGE = 20;

    /**
     * Matches of the query in the given items, one page of them.
     *
     * Every word of the query must appear in the item's searchable text,
     * ignoring case and accents ("siti hadi" finds "Siti Hadiyanti").
     *
     * @template T of array
     *
     * @param  list<T>  $items
     * @param  callable(T): string  $text  searchable text of one item
     * @return array{data: list<T>, page: int, next_page: ?int, total: int}
     */
    public static function page(array $items, string $query, int $page, callable $text, int $perPage = self::PER_PAGE): array
    {
        $words = array_values(array_filter(explode(' ', self::normalize($query))));

        if ($words !== []) {
            $items = array_values(array_filter($items, function (array $item) use ($words, $text) {
                $haystack = self::normalize($text($item));

                foreach ($words as $word) {
                    if (! str_contains($haystack, $word)) {
                        return false;
                    }
                }

                return true;
            }));
        }

        $total = count($items);
        $page = max(1, $page);

        return [
            'data' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'page' => $page,
            'next_page' => $page * $perPage < $total ? $page + 1 : null,
            'total' => $total,
        ];
    }

    private static function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($value))));
    }
}
