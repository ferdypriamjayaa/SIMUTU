<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-only Jotform API client for form definitions: the account's form
 * list, one form's properties, and its questions.
 *
 * Only those endpoints can be reached (see ALLOWED): SIMUTU never reads
 * submissions, and the submission counters Jotform includes in form
 * properties are dropped before anything is returned. The API key travels
 * in the APIKEY header, never in a URL, and is never logged.
 */
class JotformApi
{
    /** Endpoint patterns this client may call. Everything else is refused. */
    private const ALLOWED = [
        '#^user/forms$#',
        '#^form/\d{5,32}$#',
        '#^form/\d{5,32}/questions$#',
    ];

    /** Form properties kept; counters such as `count`, `new` and `last_submission` are not. */
    public const FORM_FIELDS = ['id', 'title', 'status', 'created_at', 'updated_at', 'type'];

    private const PAGE_SIZE = 1000;

    public function isConfigured(): bool
    {
        return filled(config('services.jotform.api_key')) && filled(config('services.jotform.base_url'));
    }

    /**
     * Every form of the account (enabled and disabled), properties only.
     *
     * @return list<array<string, string>>
     *
     * @throws JotformApiException
     */
    public function forms(): array
    {
        $forms = [];

        for ($offset = 0; ; $offset += self::PAGE_SIZE) {
            $page = $this->get('user/forms', ['limit' => self::PAGE_SIZE, 'offset' => $offset, 'orderby' => 'created_at']);

            if (! array_is_list($page)) {
                $this->fail('malformed');
            }

            foreach ($page as $form) {
                if (is_array($form) && isset($form['id'])) {
                    $forms[] = self::formProperties($form);
                }
            }

            if (count($page) < self::PAGE_SIZE) {
                return $forms;
            }
        }
    }

    /**
     * @return array<string, string>
     *
     * @throws JotformApiException
     */
    public function form(string $formId): array
    {
        return self::formProperties($this->get("form/{$formId}"));
    }

    /**
     * The form's questions keyed by question ID, exactly as Jotform defines them.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws JotformApiException
     */
    public function questions(string $formId): array
    {
        return $this->get("form/{$formId}/questions");
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, string>
     */
    public static function formProperties(array $form): array
    {
        return array_map('strval', array_intersect_key($form, array_flip(self::FORM_FIELDS)));
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<mixed>
     *
     * @throws JotformApiException
     */
    private function get(string $endpoint, array $query = []): array
    {
        if (! collect(self::ALLOWED)->contains(fn (string $pattern) => preg_match($pattern, $endpoint))) {
            throw new JotformApiException('Endpoint Jotform ini tidak diizinkan: SIMUTU hanya membaca definisi formulir.');
        }

        if (! $this->isConfigured()) {
            throw new JotformApiException('API Jotform belum dikonfigurasi. Isi JOTFORM_API_KEY pada file .env.');
        }

        try {
            $response = Http::baseUrl((string) config('services.jotform.base_url'))
                ->withHeaders(['APIKEY' => (string) config('services.jotform.api_key')])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(max(1, (int) config('services.jotform.timeout', 20)))
                ->retry(2, 500, fn ($exception) => $exception instanceof ConnectionException, throw: false)
                ->get($endpoint, $query);
        } catch (ConnectionException) {
            $this->fail('connection', $endpoint);
        }

        if (! $response->successful()) {
            $this->fail(match ($response->status()) {
                401, 403 => 'unauthorized',
                404 => 'not_found',
                429 => 'rate_limited',
                default => 'server_error',
            }, $endpoint, $response->status());
        }

        $content = $response->json('content');

        if (! is_array($content)) {
            $this->fail('malformed', $endpoint, $response->status());
        }

        return $content;
    }

    private function fail(string $reason, ?string $endpoint = null, ?int $status = null): never
    {
        // The endpoint path holds no secret; the key is only in a header.
        Log::warning('Jotform API request failed', ['reason' => $reason, 'endpoint' => $endpoint, 'status' => $status]);

        throw new JotformApiException(match ($reason) {
            'connection' => 'Server Jotform tidak dapat dihubungi. Coba lagi beberapa saat lagi.',
            'unauthorized' => 'API key Jotform ditolak. Periksa JOTFORM_API_KEY.',
            'not_found' => 'Formulir tidak ditemukan di Jotform.',
            'rate_limited' => 'Jotform sedang membatasi jumlah permintaan. Coba lagi nanti.',
            'malformed' => 'Jawaban dari Jotform tidak dapat dibaca.',
            default => 'Jotform sedang mengalami gangguan. Coba lagi beberapa saat lagi.',
        });
    }
}
