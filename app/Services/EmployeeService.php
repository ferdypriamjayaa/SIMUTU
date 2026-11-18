<?php

namespace App\Services;

use App\Models\Unit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Active hospital employees from the HRIS API (docs/api-karyawan-rs.md).
 *
 * Only what SIMUTU needs is kept: name, division and department. The
 * employee's NIP (field `nip`; HRIS sent a `nik` field before) is used solely
 * to build an opaque, stable identifier (an HMAC with the app key) and is
 * then discarded, so the cached lists never contain it. The one exception is
 * details(), which reads a single employee's NIP fresh from HRIS so an
 * administrator can use it as that account's login NIP. Lists are
 * cached; the last good list is kept longer and used while HRIS is
 * unreachable.
 *
 * @phpstan-type Employee array{id: string, name: string, division: ?string, department: ?string}
 */
class EmployeeService
{
    public const ENDPOINT = '/api/karyawan-rs';

    /** Known hospital company codes and their labels. */
    public const COMPANIES = [
        '0001' => 'RS BJB',
        '0002' => 'RS BRB',
    ];

    /**
     * Bumped whenever the identifier scheme changes, so cached lists built
     * with the old scheme are not served. v2: identity from `nip` (was `nik`).
     */
    private const CACHE_VERSION = 'v2';

    /** @var array<string, array{id: string, name: string, company: string, nip: ?string}|null> per-request lookups by id */
    private array $details = [];

    public function isConfigured(): bool
    {
        $config = config('services.hris');

        return filled($config['url']) && filled($config['username']) && filled($config['password'])
            && $this->companyCodes() !== [];
    }

    /**
     * Company codes SIMUTU may query, from HRIS_COMPANY_CODES.
     *
     * @return array<string, string> code => label
     */
    public function companyCodes(): array
    {
        $codes = [];

        foreach (config('services.hris.company_codes', []) as $code) {
            if (preg_match('/^\d{4}$/', $code)) {
                $codes[$code] = self::COMPANIES[$code] ?? "Kode {$code}";
            }
        }

        return $codes;
    }

    /**
     * Every active employee of one hospital company, sorted by name.
     *
     * @return list<Employee>
     *
     * @throws HrisException
     */
    public function employees(string $companyCode, bool $refresh = false): array
    {
        $this->ensureUsable($companyCode);

        $key = $this->cacheKey($companyCode);

        if (! $refresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $employees = $this->fetchAll($companyCode);
        } catch (HrisException $exception) {
            // HRIS is down or refusing: fall back to the last good list.
            if (is_array($stale = Cache::get($key.':stale'))) {
                return $stale;
            }

            throw $exception;
        }

        Cache::put($key, $employees, now()->addSeconds(max(60, (int) config('services.hris.cache_ttl'))));
        Cache::put($key.':stale', $employees, now()->addSeconds(max(60, (int) config('services.hris.stale_ttl'))));

        return $employees;
    }

    /**
     * Employees placed in the HRIS divisions/departments mapped to the unit.
     * An unmapped unit gets an empty list without calling HRIS.
     *
     * @return list<Employee>
     *
     * @throws HrisException
     */
    public function forUnit(Unit $unit): array
    {
        $mappings = $unit->hrisMappings()->get()->groupBy('company_code');
        $matched = [];

        foreach ($mappings as $companyCode => $companyMappings) {
            if (! array_key_exists($companyCode, $this->companyCodes())) {
                continue;
            }

            foreach ($this->employees($companyCode) as $employee) {
                if ($companyMappings->contains(fn ($mapping) => $mapping->matches($employee))) {
                    $matched[$employee['id']] = $employee;
                }
            }
        }

        $matched = array_values($matched);
        usort($matched, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $matched;
    }

    /**
     * Distinct division/department placements of one company with the number
     * of employees in each, for the mapping page.
     *
     * @return list<array{division: ?string, department: ?string, employees: int}>
     *
     * @throws HrisException
     */
    public function placements(string $companyCode, bool $refresh = false): array
    {
        $placements = [];

        foreach ($this->employees($companyCode, $refresh) as $employee) {
            $key = $employee['division']."\n".$employee['department'];
            $placements[$key] ??= ['division' => $employee['division'], 'department' => $employee['department'], 'employees' => 0];
            $placements[$key]['employees']++;
        }

        $placements = array_values($placements);
        usort($placements, fn (array $a, array $b) => [$a['division'] ?? "\u{FFFF}", $a['department'] ?? "\u{FFFF}"] <=> [$b['division'] ?? "\u{FFFF}", $b['department'] ?? "\u{FFFF}"]);

        return $placements;
    }

    /**
     * Active employees of every configured company, merged and sorted by
     * name, for picking the person behind a SIMUTU account. A company that
     * HRIS cannot serve is skipped and reported in `errors`.
     *
     * @return array{employees: list<array{id: string, name: string, company: string}>, errors: list<string>}
     */
    public function directory(): array
    {
        if (! $this->isConfigured()) {
            return ['employees' => [], 'errors' => ['Integrasi HRIS belum dikonfigurasi.']];
        }

        $employees = [];
        $errors = [];

        foreach ($this->companyCodes() as $code => $label) {
            try {
                foreach ($this->employees($code) as $employee) {
                    $employees[$employee['id']] ??= ['id' => $employee['id'], 'name' => $employee['name'], 'company' => $code];
                }
            } catch (HrisException $exception) {
                $errors[] = "{$label}: {$exception->getMessage()}";
            }
        }

        $employees = array_values($employees);
        usort($employees, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return ['employees' => $employees, 'errors' => $errors];
    }

    /**
     * One active employee of any configured company by identifier.
     *
     * @return array{id: string, name: string, company: string}|null
     *
     * @throws HrisException when no company list could be read at all
     */
    public function find(string $id): ?array
    {
        $directory = $this->directory();

        foreach ($directory['employees'] as $employee) {
            if ($employee['id'] === $id) {
                return $employee;
            }
        }

        if ($directory['errors'] !== []) {
            throw new HrisException(implode(' ', $directory['errors']));
        }

        return null;
    }

    /**
     * One employee with the HRIS NIP, for filling an account's login NIP. It is read
     * fresh from HRIS (a name search within the employee's company, matched
     * on the opaque identifier) and is never cached; it is kept only for the
     * current request.
     *
     * @return array{id: string, name: string, company: string, nip: ?string}|null null when not an active employee
     *
     * @throws HrisException
     */
    public function details(string $id): ?array
    {
        if (array_key_exists($id, $this->details)) {
            return $this->details[$id];
        }

        $employee = $this->find($id);

        if ($employee !== null) {
            $this->ensureUsable($employee['company']);
            $body = $this->request(['kode' => $employee['company'], 'nama' => $employee['name'], 'per_page' => 200, 'page' => 1]);
            $match = collect($body['data'])->first(fn (array $row) => ($this->employee($row)['id'] ?? null) === $id);

            $employee = $match === null ? null : [...$employee, 'nip' => $this->nip($match)];
        }

        return $this->details[$id] = $employee;
    }

    public function forget(string $companyCode): void
    {
        Cache::forget($this->cacheKey($companyCode));
    }

    /**
     * @return list<Employee>
     *
     * @throws HrisException
     */
    private function fetchAll(string $companyCode): array
    {
        $perPage = (int) config('services.hris.per_page', 200);
        $maxPages = (int) config('services.hris.max_pages', 50);
        $employees = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $body = $this->request(['kode' => $companyCode, 'per_page' => $perPage, 'page' => $page]);
            $rows = $body['data'];

            foreach ($rows as $row) {
                if ($employee = $this->employee($row)) {
                    $employees[$employee['id']] = $employee;
                }
            }

            $total = (int) ($body['pagination']['total'] ?? 0);
            $fetchedRows = ($page - 1) * $perPage + count($rows);

            if ($rows === [] || count($rows) < $perPage || ($total > 0 && $fetchedRows >= $total)) {
                break;
            }

            if ($page === $maxPages) {
                Log::warning('HRIS employee list truncated', ['company' => $companyCode, 'pages' => $maxPages]);
            }
        }

        $employees = array_values($employees);
        usort($employees, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $employees;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array{data: list<array<string, mixed>>, pagination: array<string, mixed>}
     *
     * @throws HrisException
     */
    private function request(array $query): array
    {
        $config = config('services.hris');

        try {
            $response = Http::baseUrl($config['url'])
                ->withBasicAuth((string) $config['username'], (string) $config['password'])
                ->acceptJson()
                ->connectTimeout(min(5, max(1, (int) $config['timeout'])))
                ->timeout(max(1, (int) $config['timeout']))
                // Two attempts in total, for network failures only: 4xx answers are
                // final, and retrying a 429 would only make the rate limit worse.
                ->retry(2, 500, fn ($exception) => $exception instanceof ConnectionException, throw: false)
                ->get(self::ENDPOINT, $query);
        } catch (ConnectionException) {
            $this->fail('connection', $query);
        }

        if (! $response->successful()) {
            $this->fail($this->reason($response), $query, $response->status());
        }

        $body = $response->json();

        if (! is_array($body) || ($body['success'] ?? null) !== true || ! is_array($body['data'] ?? null)) {
            $this->fail('malformed', $query, $response->status());
        }

        return ['data' => array_values(array_filter($body['data'], 'is_array')), 'pagination' => (array) ($body['pagination'] ?? [])];
    }

    /**
     * Keep only the fields SIMUTU uses; the NIP becomes an opaque identifier.
     *
     * @param  array<string, mixed>  $row
     * @return Employee|null
     */
    private function employee(array $row): ?array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($row['nama'] ?? '')));

        if ($name === '') {
            return null;
        }

        $text = fn ($value) => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $division = $text($row['divisi'] ?? null);
        $department = $text($row['departemen'] ?? null);
        $nip = $this->nip($row);

        // Without a NIP the person is identified by name and placement.
        $identity = $nip !== null ? "nip:{$nip}" : 'name:'.mb_strtolower("{$name}|{$division}|{$department}");

        return [
            'id' => substr(hash_hmac('sha256', $identity, (string) config('app.key')), 0, 32),
            'name' => mb_substr($name, 0, 255),
            'division' => $division,
            'department' => $department,
        ];
    }

    /**
     * The employee's NIP exactly as HRIS sends it (field `nip`, usually 11
     * digits). Only strings are accepted, so leading zeros survive; the old
     * `nik` field is deliberately ignored, so a NIK never becomes a login NIP.
     *
     * @param  array<string, mixed>  $row
     */
    private function nip(array $row): ?string
    {
        $nip = $row['nip'] ?? null;

        return is_string($nip) && trim($nip) !== '' ? trim($nip) : null;
    }

    private function reason(Response $response): string
    {
        return match ($response->status()) {
            401, 403 => 'unauthorized',
            404 => 'unknown_company',
            422 => 'invalid_request',
            429 => 'rate_limited',
            default => 'server_error',
        };
    }

    /**
     * Log for the administrator (no credentials, no employee data) and stop.
     *
     * @param  array<string, scalar>  $query
     */
    private function fail(string $reason, array $query, ?int $status = null): never
    {
        Log::warning('HRIS employee API request failed', [
            'reason' => $reason,
            'status' => $status,
            'company' => $query['kode'] ?? null,
            'page' => $query['page'] ?? null,
        ]);

        throw new HrisException(match ($reason) {
            'connection' => 'Server HRIS tidak dapat dihubungi. Coba lagi beberapa saat lagi.',
            'unauthorized' => 'Kredensial API HRIS ditolak. Hubungi administrator untuk memeriksa pengaturan HRIS.',
            'unknown_company' => 'Kode perusahaan tidak dikenali oleh HRIS.',
            'invalid_request' => 'Permintaan ke HRIS tidak valid. Hubungi administrator.',
            'rate_limited' => 'HRIS sedang membatasi jumlah permintaan. Coba lagi dalam satu menit.',
            'malformed' => 'Jawaban dari HRIS tidak dapat dibaca.',
            default => 'HRIS sedang mengalami gangguan. Coba lagi beberapa saat lagi.',
        });
    }

    /**
     * @throws HrisException
     */
    private function ensureUsable(string $companyCode): void
    {
        if (! $this->isConfigured()) {
            throw new HrisException('Integrasi HRIS belum dikonfigurasi.');
        }

        if (! array_key_exists($companyCode, $this->companyCodes())) {
            throw new HrisException('Kode perusahaan tidak termasuk dalam pengaturan HRIS SIMUTU.');
        }
    }

    private function cacheKey(string $companyCode): string
    {
        // Changing the endpoint or credentials invalidates old lists.
        return 'hris:employees:'.self::CACHE_VERSION.':'.$companyCode.':'.substr(sha1(config('services.hris.url').'|'.config('services.hris.username')), 0, 12);
    }
}
