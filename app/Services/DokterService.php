<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Active doctors of every hospital from the Doctor API (docs/API-Dokter.md):
 * name, specialty and hospital. The list is cached; the last good list is
 * kept longer and used while the API is unreachable.
 *
 * The API sends no identifier, so each doctor gets an opaque, stable one (an
 * HMAC of name and hospital with the app key) that the observation form
 * submits instead of the name.
 *
 * @phpstan-type Doctor array{id: string, name: string, specialty: ?string, hospital: ?string}
 */
class DokterService
{
    /**
     * Doctor API hospital names per HRIS company code
     * (EmployeeService::COMPANIES), used to list a unit's own hospital first.
     */
    public const HOSPITALS = [
        '0001' => 'RSU Syifa Medika Banjarbaru',
        '0002' => 'RSU Syifa Medika Barabai',
    ];

    private const CACHE_VERSION = 'v1';

    /** @var list<Doctor>|null per-request copy */
    private ?array $doctors = null;

    public function isConfigured(): bool
    {
        $config = config('services.dokter');

        return filled($config['url'] ?? null) && filled($config['username'] ?? null) && filled($config['password'] ?? null);
    }

    /**
     * Every active doctor, sorted by name.
     *
     * @return list<Doctor>
     *
     * @throws DokterException
     */
    public function doctors(bool $refresh = false): array
    {
        if (! $refresh && $this->doctors !== null) {
            return $this->doctors;
        }

        if (! $this->isConfigured()) {
            throw new DokterException('Integrasi API Dokter belum dikonfigurasi.');
        }

        $key = $this->cacheKey();

        if (! $refresh && is_array($cached = Cache::get($key))) {
            return $this->doctors = $cached;
        }

        try {
            return $this->refresh();
        } catch (DokterException $exception) {
            // The API is down or refusing: fall back to the last good list.
            if (is_array($stale = Cache::get($key.':stale'))) {
                return $this->doctors = $stale;
            }

            throw $exception;
        }
    }

    /**
     * Fetch the list from the API now and cache it. Unlike doctors(), a
     * failure is always reported (the last good list stays cached), so an
     * administrator's explicit reload never claims a success it did not get.
     *
     * @return list<Doctor>
     *
     * @throws DokterException
     */
    public function refresh(): array
    {
        if (! $this->isConfigured()) {
            throw new DokterException('Integrasi API Dokter belum dikonfigurasi.');
        }

        $doctors = $this->fetch();
        $key = $this->cacheKey();

        Cache::put($key, $doctors, now()->addSeconds(max(60, (int) config('services.dokter.cache_ttl'))));
        Cache::put($key.':stale', $doctors, now()->addSeconds(max(60, (int) config('services.dokter.stale_ttl'))));

        return $this->doctors = $doctors;
    }

    /**
     * Doctors grouped by hospital. Hospitals of the given HRIS company codes
     * come first, the rest follow alphabetically; doctors without a hospital
     * are listed last.
     *
     * @param  list<string>  $preferredCompanies
     * @return array<string, list<Doctor>> hospital name => doctors
     *
     * @throws DokterException
     */
    public function byHospital(array $preferredCompanies = []): array
    {
        $preferred = array_values(array_filter(array_map(fn ($code) => self::HOSPITALS[$code] ?? null, $preferredCompanies)));
        $groups = [];

        foreach ($this->doctors() as $doctor) {
            $groups[$doctor['hospital'] ?? 'Rumah sakit tidak tercatat'][] = $doctor;
        }

        uksort($groups, function (string $a, string $b) use ($preferred) {
            $rank = fn (string $name) => match (true) {
                in_array($name, $preferred, true) => 0,
                $name === 'Rumah sakit tidak tercatat' => 2,
                default => 1,
            };

            return [$rank($a), $a] <=> [$rank($b), $b];
        });

        return $groups;
    }

    /**
     * Number of doctors per hospital, for the mapping page.
     *
     * @return array<string, int>
     *
     * @throws DokterException
     */
    public function summary(): array
    {
        return array_map('count', $this->byHospital());
    }

    /**
     * @return Doctor|null
     *
     * @throws DokterException
     */
    public function find(string $id): ?array
    {
        foreach ($this->doctors() as $doctor) {
            if ($doctor['id'] === $id) {
                return $doctor;
            }
        }

        return null;
    }

    /**
     * @return list<Doctor>
     *
     * @throws DokterException
     */
    private function fetch(): array
    {
        $config = config('services.dokter');

        try {
            $response = Http::withBasicAuth((string) $config['username'], (string) $config['password'])
                ->acceptJson()
                ->connectTimeout(min(5, max(1, (int) $config['timeout'])))
                ->timeout(max(1, (int) $config['timeout']))
                // Two attempts in total, for network failures only: 4xx answers are
                // final, and retrying a 429 would only make the rate limit worse.
                ->retry(2, 500, fn ($exception) => $exception instanceof ConnectionException, throw: false)
                ->get($config['url']);
        } catch (ConnectionException) {
            $this->fail('connection');
        }

        if (! $response->successful()) {
            $this->fail(match ($response->status()) {
                401, 403 => 'unauthorized',
                404 => 'not_found',
                429 => 'rate_limited',
                default => 'server_error',
            }, $response->status());
        }

        $body = $response->json();

        if (! is_array($body) || ! is_array($body['data'] ?? null)) {
            $this->fail('malformed', $response->status());
        }

        $doctors = [];

        foreach ($body['data'] as $row) {
            if (is_array($row) && ($doctor = $this->doctor($row))) {
                $doctors[$doctor['id']] = $doctor;
            }
        }

        $doctors = array_values($doctors);
        usort($doctors, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $doctors;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return Doctor|null
     */
    private function doctor(array $row): ?array
    {
        $text = fn ($value) => is_string($value) && trim($value) !== '' ? trim(preg_replace('/\s+/u', ' ', $value)) : null;
        $name = $text($row['nama'] ?? null);

        if ($name === null) {
            return null;
        }

        $hospital = $text($row['rumah_sakit'] ?? null);

        return [
            'id' => substr(hash_hmac('sha256', 'doctor:'.mb_strtolower($name.'|'.$hospital), (string) config('app.key')), 0, 32),
            'name' => mb_substr($name, 0, 255),
            'specialty' => $text($row['spesialis'] ?? null),
            'hospital' => $hospital,
        ];
    }

    /**
     * Log for the administrator (no credentials, no URL) and stop.
     */
    private function fail(string $reason, ?int $status = null): never
    {
        Log::warning('Doctor API request failed', ['reason' => $reason, 'status' => $status]);

        throw new DokterException(match ($reason) {
            'connection' => 'Server API Dokter tidak dapat dihubungi. Coba lagi beberapa saat lagi.',
            'unauthorized' => 'Kredensial API Dokter ditolak. Hubungi administrator untuk memeriksa pengaturan API Dokter.',
            'not_found' => 'Alamat API Dokter tidak ditemukan. Hubungi administrator untuk memeriksa DOKTER_API_URL.',
            'rate_limited' => 'API Dokter sedang membatasi jumlah permintaan. Coba lagi dalam satu menit.',
            'malformed' => 'Jawaban dari API Dokter tidak dapat dibaca.',
            default => 'API Dokter sedang mengalami gangguan. Coba lagi beberapa saat lagi.',
        });
    }

    private function cacheKey(): string
    {
        // Changing the endpoint or credentials invalidates old lists.
        return 'dokter:doctors:'.self::CACHE_VERSION.':'.substr(sha1(config('services.dokter.url').'|'.config('services.dokter.username')), 0, 12);
    }
}
