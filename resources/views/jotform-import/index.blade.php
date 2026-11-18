@php
    use App\Models\IndicatorField;

    $states = [
        'imported' => ['green', 'Sudah diimpor'],
        'changed' => ['amber', 'Definisi berubah'],
        'ready' => ['brand', 'Siap diimpor'],
        'unmapped' => ['amber', 'Perlu pemetaan'],
        'error' => ['red', 'Tidak dapat diimpor'],
    ];
    $resultColors = ['field' => 'green', 'builtin' => 'navy', 'skipped' => 'gray', 'unsupported' => 'red'];
    $resultLabels = ['field' => 'Diimpor', 'builtin' => 'Field bawaan', 'skipped' => 'Dilewati', 'unsupported' => 'Tidak didukung'];
    $importable = ($counts['ready'] ?? 0) + ($counts['changed'] ?? 0);
@endphp

<x-app-layout title="Impor Formulir Jotform">
    <x-slot name="actions">
        <form method="POST" action="{{ route('jotform-import.import-all') }}"
            onsubmit="return confirm('Impor {{ $importable }} formulir yang siap? Setiap formulir dibuat sebagai draf dan belum dipublikasikan.')">
            @csrf
            <x-button icon="download" loading-text="Mengimpor…" :disabled="$importable === 0">Impor Semua yang Siap ({{ $importable }})</x-button>
        </form>
    </x-slot>

    <div class="space-y-6">
        <x-flash />

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-stat-card label="Definisi ditemukan" :value="$forms->count()" icon="clipboard" tone="navy" :hint="$fieldTotal.' field dapat diimpor'" />
            <x-stat-card label="Sudah diimpor" :value="$counts['imported'] ?? 0" icon="check-circle" tone="green" />
            <x-stat-card label="Siap diimpor" :value="$importable" icon="download" tone="brand" />
            <x-stat-card label="Perlu tindakan" :value="($counts['unmapped'] ?? 0) + ($counts['error'] ?? 0)" icon="warning" tone="amber" />
        </div>

        @forelse ($forms as $form)
            @php
                $definition = $form['definition'];
                $record = $form['record'];
                $suggestion = $form['suggestion'];
                $formId = $definition?->formId;
                $bag = 'mapping'.$formId;
                $mappingErrors = $errors->getBag($bag);
                $result = $formId ? ($results[$formId] ?? null) : null;
                $locked = (bool) $record?->formVersion;
                $current = $record?->indicator ?? (($suggestion['exact'] ?? false) ? $suggestion['indicator'] : null);
                $openMapping = $definition && ! $locked && ($mappingErrors->any() || ! $record?->indicator);
            @endphp

            <section id="form-{{ $formId ?? $form['directory'] }}" class="card scroll-mt-24">
                <header class="card-header items-start">
                    <div class="min-w-0">
                        <p class="font-mono text-xs text-gray-400 dark:text-slate-500">{{ $form['directory'] }}</p>
                        <h2 class="card-title mt-0.5">{{ $definition?->title ?? 'Berkas ekspor tidak terbaca' }}</h2>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <x-badge :color="$states[$form['state']][0]" dot>{{ $states[$form['state']][1] }}</x-badge>
                            @if ($definition)
                                <x-badge color="gray">Form ID {{ $definition->formId }}</x-badge>
                                <x-badge color="gray">Jotform: {{ $definition->status ?: '-' }}</x-badge>
                                <x-badge color="navy">{{ count($definition->fields) }} field</x-badge>
                            @endif
                        </div>
                    </div>

                    @if ($definition && in_array($form['state'], ['ready', 'changed'], true))
                        <form method="POST" action="{{ route('jotform-import.import', $formId) }}">
                            @csrf
                            <x-button size="sm" icon="download" loading-text="Mengimpor…">{{ $form['state'] === 'changed' ? 'Impor Ulang' : 'Impor' }}</x-button>
                        </form>
                    @elseif ($record?->formVersion)
                        <x-button size="sm" variant="secondary" icon="clipboard" :href="route('form-builder.show', $record->indicator_id)">Buka Formulir</x-button>
                    @endif
                </header>

                <div class="card-body space-y-5">
                    @if ($result)
                        <x-alert :type="$result['ok'] ? 'success' : 'error'">{{ $result['message'] }}</x-alert>
                    @endif

                    @if ($form['error'])
                        <x-alert type="error" title="Berkas tidak dapat dibaca">{{ $form['error'] }}</x-alert>
                    @endif

                    @if ($definition && $definition->errors)
                        <x-alert type="error" title="Formulir ini memuat pertanyaan yang belum didukung">
                            <ul class="list-inside list-disc space-y-0.5">
                                @foreach ($definition->errors as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </x-alert>
                    @endif

                    @if ($definition)
                        {{-- Mapping --}}
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-slate-800">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 text-sm">
                                    <p class="text-xs font-medium text-gray-500 dark:text-slate-400">Indikator SIMUTU</p>
                                    @if ($current)
                                        <p class="mt-1 font-semibold text-navy-900 dark:text-slate-100">{{ $current->name }}</p>
                                        <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">
                                            @if ($record?->indicator)
                                                {{ $record->indicator->category?->name }} · pemetaan dikonfirmasi
                                            @else
                                                Judul sama persis dengan indikator ini · dipetakan otomatis saat impor
                                            @endif
                                        </p>
                                    @else
                                        <p class="mt-1 font-semibold text-amber-700 dark:text-amber-300">Belum dipetakan</p>
                                        <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">
                                            @if ($suggestion)
                                                Judulnya berkaitan dengan indikator <strong>{{ $suggestion['indicator']->name }}</strong>, tetapi tidak sama.
                                                Pastikan dulu apakah ini indikator yang sama atau indikator tersendiri.
                                            @else
                                                Tidak ada indikator SIMUTU dengan judul yang mirip.
                                            @endif
                                        </p>
                                    @endif

                                    @if ($current && ! $record?->formVersion && $current->publishedFormVersion)
                                        <p class="mt-2 flex items-start gap-1.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                                            <x-icon name="warning" class="mt-px h-3.5 w-3.5" />
                                            <span>Indikator ini sudah memiliki formulir terbit (versi {{ $current->publishedFormVersion->version }}{{ $current->observations_count ? ', '.$current->observations_count.' observasi' : '' }}).
                                                Impor membuat draf versi baru; bila draf itu dipublikasikan, formulir yang sekarang dipakai akan digantikan.</span>
                                        </p>
                                    @endif

                                    @if ($record?->formVersion)
                                        <p class="mt-2 text-xs text-gray-500 dark:text-slate-400">
                                            Diimpor {{ $record->imported_at?->format('d-m-Y H:i') }} sebagai versi {{ $record->formVersion->version }}
                                            ({{ $record->formVersion->statusLabel() }}).
                                            @if ($form['state'] === 'changed')
                                                Berkas ekspor berbeda dari yang terakhir diimpor.
                                            @endif
                                        </p>
                                    @endif
                                </div>
                            </div>

                            @unless ($locked)
                                <details class="mt-4 border-t border-gray-100 pt-4 dark:border-slate-800" @if ($openMapping) open @endif>
                                    <summary class="link cursor-pointer text-sm">{{ $record?->indicator ? 'Ubah pemetaan' : 'Konfirmasi pemetaan indikator' }}</summary>

                                    <form method="POST" action="{{ route('jotform-import.map', $formId) }}" class="mt-4 space-y-4"
                                        x-data="{ target: @js($mappingErrors->any() ? old('target', 'existing') : ($record?->indicator ? 'existing' : 'new')), category: @js($mappingErrors->any() ? old('category', 'imp-rs') : 'imp-rs') }">
                                        @csrf

                                        <div class="flex flex-wrap gap-2">
                                            <label class="choice" :class="target === 'existing' && 'border-brand-500 bg-brand-50 text-brand-700 dark:border-brand-500/70 dark:bg-brand-500/15 dark:text-brand-300'">
                                                <input type="radio" name="target" value="existing" x-model="target" class="sr-only"> Indikator yang sudah ada
                                            </label>
                                            <label class="choice" :class="target === 'new' && 'border-brand-500 bg-brand-50 text-brand-700 dark:border-brand-500/70 dark:bg-brand-500/15 dark:text-brand-300'">
                                                <input type="radio" name="target" value="new" x-model="target" class="sr-only"> Buat indikator baru
                                            </label>
                                        </div>

                                        <div x-show="target === 'existing'">
                                            <label class="form-label" for="indicator-{{ $formId }}">Indikator</label>
                                            <select name="indicator_id" id="indicator-{{ $formId }}" class="form-control" :disabled="target !== 'existing'">
                                                <option value="">Pilih indikator…</option>
                                                @foreach ($indicators->groupBy(fn ($indicator) => $indicator->category?->name ?? '-') as $categoryName => $group)
                                                    <optgroup label="{{ $categoryName }}">
                                                        @foreach ($group as $indicator)
                                                            @php($takenBy = $takenIndicatorIds[$indicator->id] ?? null)
                                                            <option value="{{ $indicator->id }}"
                                                                @selected((string) ($mappingErrors->any() ? old('indicator_id') : ($record?->indicator_id ?? '')) === (string) $indicator->id)
                                                                @disabled($takenBy && $takenBy !== $formId)>
                                                                {{ $indicator->name }}{{ $takenBy && $takenBy !== $formId ? ' (sudah dipetakan ke formulir lain)' : '' }}
                                                            </option>
                                                        @endforeach
                                                    </optgroup>
                                                @endforeach
                                            </select>
                                            <x-input-error :messages="$mappingErrors->get('indicator_id')" />
                                        </div>

                                        <div x-show="target === 'new'" x-cloak class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <div class="sm:col-span-2">
                                                <label class="form-label form-label-required" for="name-{{ $formId }}">Nama indikator</label>
                                                <input type="text" name="name" id="name-{{ $formId }}" maxlength="255" class="form-control" :disabled="target !== 'new'"
                                                    value="{{ $mappingErrors->any() ? old('name') : $definition->title }}">
                                                <x-input-error :messages="$mappingErrors->get('name')" />
                                            </div>
                                            <div>
                                                <label class="form-label form-label-required" for="category-{{ $formId }}">Kategori</label>
                                                <select name="category" id="category-{{ $formId }}" class="form-control" x-model="category" :disabled="target !== 'new'">
                                                    @foreach ($categories as $category)
                                                        <option value="{{ $category->slug }}">{{ $category->name }}</option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$mappingErrors->get('category')" />
                                            </div>
                                            <div x-show="category === 'imp-unit'">
                                                <label class="form-label form-label-required" for="unit-{{ $formId }}">Unit penanggung jawab</label>
                                                <select name="unit_id" id="unit-{{ $formId }}" class="form-control" :disabled="target !== 'new' || category !== 'imp-unit'">
                                                    <option value="">Pilih unit…</option>
                                                    @foreach ($units as $unit)
                                                        <option value="{{ $unit->id }}" @selected($mappingErrors->any() && (string) old('unit_id') === (string) $unit->id)>{{ $unit->name }}</option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$mappingErrors->get('unit_id')" />
                                            </div>
                                        </div>

                                        <x-input-error :messages="$mappingErrors->get('target')" />

                                        <x-button variant="navy" size="sm" icon="check-circle" loading-text="Menyimpan…">Konfirmasi Pemetaan</x-button>
                                    </form>
                                </details>
                            @endunless
                        </div>

                        {{-- Questions --}}
                        <details>
                            <summary class="link cursor-pointer text-sm">Pratinjau {{ count($definition->questions) }} pertanyaan dan hasil pemetaan field</summary>

                            <div class="mt-3 overflow-x-auto rounded-xl border border-gray-200 dark:border-slate-800">
                                <table class="table-base">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>ID</th>
                                            <th>Pertanyaan Jotform</th>
                                            <th>Tipe Jotform</th>
                                            <th>Hasil</th>
                                            <th>Field SIMUTU</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($definition->questions as $question)
                                            <tr>
                                                <td class="tabular-nums">{{ $question['order'] }}</td>
                                                <td class="font-mono text-xs">{{ $question['qid'] }}</td>
                                                <td class="min-w-[14rem]">
                                                    <span class="font-medium text-navy-900 dark:text-slate-100">{{ $question['label'] ?: '(tanpa label)' }}</span>
                                                    @if ($question['required'])
                                                        <span class="font-semibold text-brand-600 dark:text-brand-400" title="Wajib diisi">*</span>
                                                    @endif
                                                </td>
                                                <td class="whitespace-nowrap">{{ $question['jotform_type'] }}</td>
                                                <td><x-badge :color="$resultColors[$question['result']]">{{ $resultLabels[$question['result']] }}</x-badge></td>
                                                <td class="min-w-[18rem]">
                                                    @foreach ($question['fields'] as $field)
                                                        <div class="py-0.5">
                                                            <span class="font-medium text-navy-900 dark:text-slate-100">{{ $field['label'] }}</span>
                                                            @if ($field['is_required'])
                                                                <span class="font-semibold text-brand-600 dark:text-brand-400">*</span>
                                                            @endif
                                                            <span class="text-xs text-gray-500 dark:text-slate-400">— {{ IndicatorField::TYPES[$field['type']] }}</span>
                                                            @if ($field['options'])
                                                                <span class="block text-xs text-gray-500 dark:text-slate-400">
                                                                    {{ count($field['options']) }} opsi: {{ \Illuminate\Support\Str::limit(collect($field['options'])->pluck('label')->implode(', '), 140) }}
                                                                </span>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                    @if ($question['note'])
                                                        <p class="text-xs text-gray-500 dark:text-slate-400">{{ $question['note'] }}</p>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endif
                </div>
            </section>
        @empty
            <x-card>
                <x-empty-state icon="clipboard" title="Tidak ada berkas ekspor Jotform."
                    description="Letakkan folder ekspor (berisi form.json dan questions.json) di folder JOTFORM-EXPORT pada aplikasi." />
            </x-card>
        @endforelse
    </div>
</x-app-layout>
