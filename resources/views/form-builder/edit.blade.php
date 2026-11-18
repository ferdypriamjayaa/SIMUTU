@php
    use App\Models\IndicatorField;

    // After a failed save the submitted definition comes back, not the stored one.
    $restored = json_decode((string) old('payload', ''), true)['fields'] ?? null;

    $config = [
        'fields' => is_array($restored) ? array_values($restored) : $fields,
        'restored' => is_array($restored),
        'savedKeys' => collect($fields)->pluck('key', 'id'),
        'lockedKeys' => $lockedKeys,
        'reservedKeys' => IndicatorField::RESERVED_KEYS,
        'types' => IndicatorField::TYPES,
        'formats' => collect(IndicatorField::FORMATS)->map->label,
        'errors' => $errors->getMessages(),
    ];

    $palette = [
        IndicatorField::TYPE_TEXT => ['text', 'Satu baris teks'],
        IndicatorField::TYPE_TEXTAREA => ['document', 'Beberapa baris teks'],
        IndicatorField::TYPE_NUMBER => ['hash', 'Angka atau jumlah'],
        IndicatorField::TYPE_DATE => ['calendar', 'Pilih tanggal'],
        IndicatorField::TYPE_TIME => ['calendar', 'Jam dan menit'],
        IndicatorField::TYPE_DATETIME => ['calendar', 'Tanggal beserta jam'],
        IndicatorField::TYPE_SELECT => ['chevron-down', 'Satu pilihan dari daftar'],
        IndicatorField::TYPE_RADIO => ['check-circle', 'Satu pilihan, semua terlihat'],
        IndicatorField::TYPE_CHECKBOX => ['list', 'Boleh lebih dari satu'],
        IndicatorField::TYPE_YES_NO => ['power', 'Jawaban Ya atau Tidak'],
    ];
@endphp

<x-app-layout title="Form Builder" :subtitle="$indicator->name" :back="route('form-builder.show', $indicator)">
    <div x-data="formBuilder(@js($config))" class="space-y-5">
        <x-flash />

        <form x-ref="form" method="POST" action="{{ route('form-builder.update', [$indicator, $version]) }}" class="hidden">
            @csrf
            @method('PUT')
            <input type="hidden" name="payload" x-ref="payload">
            <input type="hidden" name="intent" x-ref="intent">
        </form>

        {{-- Toolbar --}}
        <div class="card sticky top-20 z-20 flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5">
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                <x-badge color="amber" dot>Draf · Versi {{ $version->version }}</x-badge>
                <span class="text-xs text-gray-500 dark:text-slate-400" x-text="fields.length + ' field'"></span>
                <span x-show="dirty" x-cloak class="text-xs font-medium text-amber-600 dark:text-amber-300">Ada perubahan yang belum disimpan</span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="btn btn-sm btn-ghost" x-on:click="submit('preview')">
                    <x-icon name="eye" class="h-4 w-4" /> <span>Simpan &amp; Pratinjau</span>
                </button>
                <button type="button" class="btn btn-sm btn-secondary" x-on:click="submit('save')">
                    <x-icon name="check-circle" class="h-4 w-4" /> <span>Simpan Draf</span>
                </button>
                <button type="button" class="btn btn-sm btn-primary" x-on:click="submit('publish')">
                    <x-icon name="shield" class="h-4 w-4" /> <span>Publikasikan</span>
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[13rem_minmax(0,1fr)_21rem]">
            {{-- LEFT: field types --}}
            <aside class="card p-4 lg:sticky lg:top-40" aria-label="Tipe field">
                <h2 class="card-title text-sm">Tambah Field</h2>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">Pilih tipe untuk menambahkannya ke formulir.</p>

                <div class="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-1">
                    @foreach ($palette as $type => [$icon, $hint])
                        <button type="button" x-on:click="add('{{ $type }}')"
                            class="group flex items-center gap-2.5 rounded-xl border border-gray-200 bg-white px-3 py-2 text-left transition hover:border-brand-300 hover:bg-brand-50/50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-500/20 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-brand-500/50 dark:hover:bg-brand-500/10">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300">
                                <x-icon :name="$icon" class="h-4 w-4" />
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-navy-900 dark:text-slate-100">{{ IndicatorField::TYPES[$type] }}</span>
                                <span class="hidden truncate text-xs text-gray-500 dark:text-slate-400 lg:block">{{ $hint }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>
            </aside>

            {{-- CENTER: canvas --}}
            <section class="card min-w-0" aria-label="Kanvas formulir">
                <header class="card-header">
                    <div>
                        <h2 class="card-title">Kanvas Formulir</h2>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">Klik field untuk mengatur propertinya. Seret atau gunakan panah untuk mengubah urutan.</p>
                    </div>
                </header>

                <div class="space-y-2 p-4 sm:p-5" x-on:dragover.prevent x-on:drop.prevent="drop()">
                    {{-- Always the first field of every observation form. --}}
                    <div class="flex items-center gap-3 rounded-xl border border-dashed border-gray-300 px-4 py-3 dark:border-slate-700">
                        <x-icon name="lock" class="h-4 w-4 text-gray-400 dark:text-slate-500" />
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-navy-900 dark:text-slate-100">Tanggal Observasi</p>
                            <p class="text-xs text-gray-500 dark:text-slate-400">Field bawaan sistem, selalu ada di setiap formulir.</p>
                        </div>
                    </div>

                    <template x-for="(field, index) in fields" :key="field.uid">
                        <div>
                            <p x-show="startsSection(index)" class="mb-2 mt-4 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                <span class="h-3.5 w-1 rounded-full bg-brand-500"></span>
                                <span x-text="field.section"></span>
                            </p>

                            <div x-show="dropIndex === index && dragUid !== null" class="mb-2 h-1 rounded-full bg-brand-500"></div>

                            <div draggable="true" role="button" tabindex="0"
                                x-on:dragstart="dragStart($event, field)" x-on:dragend="dragEnd()" x-on:dragover="dragOver($event, index)"
                                x-on:click="select(field.uid)" x-on:keydown.enter.self="select(field.uid)"
                                :aria-pressed="selectedUid === field.uid"
                                class="group flex cursor-pointer items-start gap-3 rounded-xl border bg-white p-3 transition focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-500/20 dark:bg-slate-900 sm:p-4"
                                :class="{
                                    'border-brand-500 ring-4 ring-brand-500/10': selectedUid === field.uid,
                                    'border-red-300 dark:border-red-500/50': selectedUid !== field.uid && hasErrors(index),
                                    'border-gray-200 hover:border-brand-300 dark:border-slate-800 dark:hover:border-brand-500/50': selectedUid !== field.uid && ! hasErrors(index),
                                    'opacity-50': dragUid === field.uid,
                                }">
                                <span class="mt-0.5 hidden cursor-grab text-gray-300 group-hover:text-gray-500 dark:text-slate-600 dark:group-hover:text-slate-400 sm:block" title="Seret untuk memindahkan">
                                    <x-icon name="grip" class="h-5 w-5" />
                                </span>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="text-sm font-semibold text-navy-900 dark:text-slate-100" x-text="field.label || '(tanpa label)'"></span>
                                        <span x-show="field.is_required" class="text-sm font-semibold text-brand-600 dark:text-brand-400" title="Wajib diisi">*</span>
                                        <span class="badge bg-gray-100 text-gray-600 ring-gray-500/15 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-400/20" x-text="types[field.type]"></span>
                                        <span x-show="isConditional(field)" x-bind:title="conditionSummary(field)" class="badge bg-brand-50 text-brand-700 ring-brand-600/20 dark:bg-brand-500/15 dark:text-brand-300 dark:ring-brand-400/30">Bersyarat</span>
                                        <span x-show="! field.is_active" class="badge bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/15 dark:text-amber-200 dark:ring-amber-400/30">Nonaktif</span>
                                        <span x-show="isLocked(field)" class="badge bg-navy-50 text-navy-700 ring-navy-600/20 dark:bg-navy-400/15 dark:text-navy-200 dark:ring-navy-300/30" title="Sudah memiliki data observasi">
                                            <x-icon name="lock" class="h-3 w-3" /> Ada data
                                        </span>
                                        <span x-show="hasErrors(index)" class="badge bg-red-50 text-red-700 ring-red-600/15 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-400/30">Perlu diperbaiki</span>
                                    </div>
                                    <p class="mt-0.5 truncate font-mono text-xs text-gray-400 dark:text-slate-500" x-text="field.key"></p>

                                    {{-- Rough impression of the control; the exact rendering is in the preview. --}}
                                    <div class="mt-2.5" :class="{ 'opacity-50': ! field.is_active }">
                                        <template x-if="isChoice(field)">
                                            <div class="flex flex-wrap gap-1.5">
                                                <template x-for="option in field.options" :key="option.uid">
                                                    <span class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-600 dark:border-slate-700 dark:text-slate-300" x-text="option.label || '(kosong)'"></span>
                                                </template>
                                                <span x-show="field.options.length === 0" class="text-xs text-red-600 dark:text-red-400">Belum ada opsi</span>
                                            </div>
                                        </template>
                                        <template x-if="field.type === 'yes_no'">
                                            <div class="flex gap-1.5">
                                                <span class="rounded-lg border border-gray-200 px-3 py-1 text-xs text-gray-600 dark:border-slate-700 dark:text-slate-300">Ya</span>
                                                <span class="rounded-lg border border-gray-200 px-3 py-1 text-xs text-gray-600 dark:border-slate-700 dark:text-slate-300">Tidak</span>
                                            </div>
                                        </template>
                                        <template x-if="! isChoice(field) && field.type !== 'yes_no'">
                                            <div class="flex items-center rounded-lg border border-gray-200 px-3 text-xs text-gray-400 dark:border-slate-700 dark:text-slate-500"
                                                :class="field.type === 'textarea' ? 'h-14 items-start pt-2' : 'h-9'"
                                                x-text="field.placeholder || ({ date: 'hh-bb-tttt', time: 'jj:mm', datetime: 'hh-bb-tttt jj:mm', number: '0' }[field.type] ?? 'Isian ' + types[field.type].toLowerCase())"></div>
                                        </template>
                                        <p x-show="field.help_text" class="mt-1.5 text-xs text-gray-500 dark:text-slate-400" x-text="field.help_text"></p>
                                    </div>
                                </div>

                                <div class="flex shrink-0 flex-col items-center gap-0.5 sm:flex-row" x-on:click.stop>
                                    <button type="button" class="btn-icon h-8 w-8" title="Naikkan" aria-label="Naikkan" x-on:click="move(index, -1)" :disabled="index === 0" :class="{ 'opacity-30': index === 0 }">
                                        <x-icon name="chevron-up" class="h-4 w-4" />
                                    </button>
                                    <button type="button" class="btn-icon h-8 w-8" title="Turunkan" aria-label="Turunkan" x-on:click="move(index, 1)" :disabled="index === fields.length - 1" :class="{ 'opacity-30': index === fields.length - 1 }">
                                        <x-icon name="chevron-down" class="h-4 w-4" />
                                    </button>
                                    <button type="button" class="btn-icon h-8 w-8" title="Duplikat" aria-label="Duplikat" x-on:click="duplicate(field)">
                                        <x-icon name="duplicate" class="h-4 w-4" />
                                    </button>
                                    <button type="button" class="btn-icon btn-icon-danger h-8 w-8" x-on:click="remove(field)" :disabled="isLocked(field)"
                                        :class="{ 'cursor-not-allowed opacity-30': isLocked(field) }"
                                        :title="isLocked(field) ? 'Field ini sudah memiliki data observasi. Nonaktifkan saja bila tidak lagi digunakan.' : 'Hapus'"
                                        aria-label="Hapus">
                                        <x-icon name="trash" class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>

                    <div x-show="dropIndex === fields.length && dragUid !== null" class="h-1 rounded-full bg-brand-500"></div>

                    <div x-show="fields.length === 0" x-cloak>
                        <x-empty-state icon="clipboard" title="Formulir masih kosong."
                            description="Tambahkan field dari daftar tipe untuk mulai menyusun formulir." />
                    </div>
                </div>
            </section>

            {{-- RIGHT: properties --}}
            <aside x-ref="properties" class="card scroll-mt-24 lg:sticky lg:top-40 lg:max-h-[calc(100vh-11rem)] lg:overflow-y-auto" aria-label="Properti field">
                <header class="card-header">
                    <h2 class="card-title">Properti Field</h2>
                </header>

                <template x-if="! selected">
                    <div class="p-5 text-sm text-gray-500 dark:text-slate-400">Pilih field di kanvas untuk mengatur label, validasi, dan opsinya.</div>
                </template>

                <template x-if="selected">
                    <div class="space-y-5 p-5">
                        <div x-show="isLocked(selected)" class="flex gap-2.5 rounded-xl border border-navy-200 bg-navy-50 p-3 text-xs text-navy-800 dark:border-navy-400/30 dark:bg-navy-400/15 dark:text-slate-200">
                            <x-icon name="lock" class="mt-0.5 h-4 w-4" />
                            <p>Field ini sudah memiliki data observasi. Kunci dan tipenya tetap, dan field tidak dapat dihapus — nonaktifkan bila tidak lagi digunakan.</p>
                        </div>

                        <div>
                            <label class="form-label form-label-required" for="fb-label">Label</label>
                            <input id="fb-label" x-ref="labelInput" type="text" maxlength="255" class="form-control form-control-sm"
                                x-model="selected.label" x-on:input="labelChanged(selected)">
                            <p class="form-error" x-show="error('label')" x-text="error('label')"></p>
                        </div>

                        <div>
                            <label class="form-label" for="fb-key">Kunci field</label>
                            <input id="fb-key" type="text" maxlength="64" class="form-control form-control-sm font-mono"
                                x-model="selected.key" x-on:input="keyChanged(selected)" :disabled="isLocked(selected)" spellcheck="false">
                            <p class="form-hint">Pengenal tetap untuk data dan laporan. Huruf kecil, angka, dan garis bawah.</p>
                            <p class="form-error" x-show="error('key')" x-text="error('key')"></p>
                        </div>

                        <div>
                            <label class="form-label" for="fb-type">Tipe field</label>
                            <select id="fb-type" class="form-control form-control-sm" x-model="selected.type" x-on:change="typeChanged(selected)" :disabled="isLocked(selected)">
                                @foreach (IndicatorField::TYPES as $type => $label)
                                    <option value="{{ $type }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="form-error" x-show="error('type')" x-text="error('type')"></p>
                        </div>

                        <div>
                            <label class="form-label" for="fb-section">Judul bagian <span class="font-normal text-gray-400 dark:text-slate-500">(opsional)</span></label>
                            <input id="fb-section" type="text" maxlength="150" list="fb-sections" class="form-control form-control-sm" x-model="selected.section">
                            <datalist id="fb-sections">
                                <template x-for="section in sections" :key="section"><option :value="section"></option></template>
                            </datalist>
                            <p class="form-hint">Field berurutan dengan judul bagian yang sama ditampilkan dalam satu kelompok.</p>
                            <p class="form-error" x-show="error('section')" x-text="error('section')"></p>
                        </div>

                        <div x-show="isText(selected) || selected.type === 'number' || selected.type === 'select'">
                            <label class="form-label" for="fb-placeholder">Placeholder</label>
                            <input id="fb-placeholder" type="text" maxlength="255" class="form-control form-control-sm" x-model="selected.placeholder">
                            <p class="form-error" x-show="error('placeholder')" x-text="error('placeholder')"></p>
                        </div>

                        <div>
                            <label class="form-label" for="fb-help">Teks bantuan</label>
                            <textarea id="fb-help" rows="2" maxlength="500" class="form-control min-h-0" x-model="selected.help_text"></textarea>
                            <p class="form-error" x-show="error('help_text')" x-text="error('help_text')"></p>
                        </div>

                        <div class="space-y-3 rounded-xl border border-gray-200 p-3 dark:border-slate-800">
                            <label class="flex cursor-pointer items-start gap-3">
                                <input type="checkbox" class="form-checkbox-base mt-0.5" x-model="selected.is_required">
                                <span class="text-sm">
                                    <span class="block font-medium text-navy-900 dark:text-slate-100">Wajib diisi</span>
                                    <span class="text-xs text-gray-500 dark:text-slate-400">Observasi tidak dapat disimpan tanpa isian ini.</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-3">
                                <input type="checkbox" class="form-checkbox-base mt-0.5" x-model="selected.is_active">
                                <span class="text-sm">
                                    <span class="block font-medium text-navy-900 dark:text-slate-100">Aktif</span>
                                    <span class="text-xs text-gray-500 dark:text-slate-400">Field nonaktif tidak ditampilkan di formulir.</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-3">
                                <input type="checkbox" class="form-checkbox-base mt-0.5" x-model="selected.show_in_summary">
                                <span class="text-sm">
                                    <span class="block font-medium text-navy-900 dark:text-slate-100">Tampilkan di ringkasan</span>
                                    <span class="text-xs text-gray-500 dark:text-slate-400">Laporan Observasi kini menampilkan setiap field sebagai kolom sendiri; pengaturan ini tidak lagi mengubah laporan.</span>
                                </span>
                            </label>
                        </div>

                        {{-- Validation: text --}}
                        <fieldset x-show="isText(selected)" class="space-y-3">
                            <legend class="form-label">Validasi teks</legend>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-xs text-gray-500 dark:text-slate-400" for="fb-min-length">Panjang minimum</label>
                                    <input id="fb-min-length" type="number" min="0" step="1" inputmode="numeric" class="form-control form-control-sm" x-model="selected.min_length">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-gray-500 dark:text-slate-400" for="fb-max-length">Panjang maksimum</label>
                                    <input id="fb-max-length" type="number" min="1" step="1" inputmode="numeric" class="form-control form-control-sm" x-model="selected.max_length"
                                        :placeholder="selected.type === 'textarea' ? '2000' : '255'">
                                </div>
                            </div>
                            <p class="form-error" x-show="error('min_length')" x-text="error('min_length')"></p>
                            <p class="form-error" x-show="error('max_length')" x-text="error('max_length')"></p>

                            <div x-show="selected.type === 'text'">
                                <label class="mb-1 block text-xs text-gray-500 dark:text-slate-400" for="fb-format">Format isian</label>
                                <select id="fb-format" class="form-control form-control-sm" x-model="selected.format">
                                    <option value="">Bebas</option>
                                    @foreach (IndicatorField::FORMATS as $format => $definition)
                                        <option value="{{ $format }}">{{ $definition['label'] }}</option>
                                    @endforeach
                                </select>
                                <p class="form-error" x-show="error('format')" x-text="error('format')"></p>
                            </div>
                        </fieldset>

                        {{-- Validation: number --}}
                        <fieldset x-show="selected.type === 'number'" class="space-y-3">
                            <legend class="form-label">Validasi angka</legend>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-xs text-gray-500 dark:text-slate-400" for="fb-min-value">Nilai minimum</label>
                                    <input id="fb-min-value" type="number" step="any" inputmode="decimal" class="form-control form-control-sm" x-model="selected.min_value">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-gray-500 dark:text-slate-400" for="fb-max-value">Nilai maksimum</label>
                                    <input id="fb-max-value" type="number" step="any" inputmode="decimal" class="form-control form-control-sm" x-model="selected.max_value">
                                </div>
                            </div>
                            <p class="form-error" x-show="error('min_value')" x-text="error('min_value')"></p>
                            <p class="form-error" x-show="error('max_value')" x-text="error('max_value')"></p>

                            <label class="flex cursor-pointer items-center gap-3 text-sm text-gray-700 dark:text-slate-300">
                                <input type="checkbox" class="form-checkbox-base" x-model="selected.allow_decimal"> Izinkan angka desimal
                            </label>

                            <div>
                                <label class="mb-1 block text-xs text-gray-500 dark:text-slate-400" for="fb-max-field">Tidak boleh melebihi isian field</label>
                                <select id="fb-max-field" class="form-control form-control-sm" x-model="selected.max_field_key">
                                    <option value="">— tidak dibandingkan —</option>
                                    <template x-for="other in numberFields(selected)" :key="other.uid">
                                        <option :value="other.key" x-text="other.label" :selected="other.key === selected.max_field_key"></option>
                                    </template>
                                </select>
                                <p class="form-error" x-show="error('max_field_key')" x-text="error('max_field_key')"></p>
                            </div>
                        </fieldset>

                        {{-- Options --}}
                        <fieldset x-show="isChoice(selected)" class="space-y-2">
                            <legend class="form-label form-label-required">Opsi</legend>

                            <template x-for="(option, optionIndex) in selected.options" :key="option.uid">
                                <div class="rounded-xl border border-gray-200 p-2 dark:border-slate-800">
                                    <div class="flex items-center gap-1">
                                        <input type="text" maxlength="255" class="form-control form-control-sm" placeholder="Label opsi" aria-label="Label opsi"
                                            x-model="option.label" x-on:input="optionLabelChanged(selected, option)">
                                        <button type="button" class="btn-icon h-8 w-8 shrink-0" title="Naikkan" aria-label="Naikkan opsi" x-on:click="moveOption(selected, optionIndex, -1)" :disabled="optionIndex === 0">
                                            <x-icon name="chevron-up" class="h-4 w-4" />
                                        </button>
                                        <button type="button" class="btn-icon h-8 w-8 shrink-0" title="Turunkan" aria-label="Turunkan opsi" x-on:click="moveOption(selected, optionIndex, 1)" :disabled="optionIndex === selected.options.length - 1">
                                            <x-icon name="chevron-down" class="h-4 w-4" />
                                        </button>
                                        <button type="button" class="btn-icon btn-icon-danger h-8 w-8 shrink-0" title="Hapus opsi" aria-label="Hapus opsi" x-on:click="removeOption(selected, optionIndex)">
                                            <x-icon name="x" class="h-4 w-4" />
                                        </button>
                                    </div>
                                    <div class="mt-1.5 flex items-center gap-2">
                                        <span class="shrink-0 text-xs text-gray-500 dark:text-slate-400">Nilai tersimpan</span>
                                        <input type="text" maxlength="100" class="form-control h-8 font-mono text-xs" aria-label="Nilai opsi" spellcheck="false"
                                            x-model="option.value" x-on:input="optionValueChanged(selected, option)">
                                    </div>
                                </div>
                            </template>

                            <button type="button" class="btn btn-sm btn-secondary w-full" x-on:click="addOption(selected)">
                                <x-icon name="plus" class="h-4 w-4" /> <span>Tambah Opsi</span>
                            </button>
                            <p class="form-error" x-show="error('options')" x-text="error('options')"></p>
                        </fieldset>

                        {{-- Conditional: shown only for some answers of an earlier single-choice field. --}}
                        <fieldset class="space-y-2" x-show="conditionTriggers(selected).length > 0 || isConditional(selected)">
                            <legend class="form-label">Tampilkan field ini</legend>
                            <select id="fb-condition" class="form-control form-control-sm" aria-label="Syarat tampil"
                                x-bind:value="selected.conditionUid ?? ''" x-on:change="setConditionTrigger(selected, $event.target.value)">
                                <option value="">Selalu</option>
                                <template x-for="trigger in conditionTriggers(selected)" :key="trigger.uid">
                                    <option :value="trigger.uid" x-text="'Jika ' + (trigger.label || trigger.key) + ' …'" :selected="trigger.uid === selected.conditionUid"></option>
                                </template>
                            </select>
                            <div x-show="conditionTrigger(selected)" class="space-y-1.5 rounded-xl border border-gray-200 p-3 dark:border-slate-800">
                                <p class="text-xs text-gray-500 dark:text-slate-400">… adalah salah satu dari:</p>
                                <template x-for="option in conditionTrigger(selected)?.options ?? []" :key="option.uid">
                                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-gray-700 dark:text-slate-300">
                                        <input type="checkbox" class="form-checkbox-base" x-bind:checked="selected.conditionOptionUids.includes(option.uid)"
                                            x-on:change="toggleConditionOption(selected, option)">
                                        <span x-text="option.label"></span>
                                    </label>
                                </template>
                            </div>
                            <p class="form-hint" x-show="conditionTrigger(selected)">Jika tersembunyi, field ini tidak wajib diisi dan tidak disimpan.</p>
                            <p class="form-error" x-show="error('visible_when_key')" x-text="error('visible_when_key')"></p>
                        </fieldset>

                        {{-- Default value --}}
                        <div>
                            <label class="form-label" for="fb-default">Nilai bawaan <span class="font-normal text-gray-400 dark:text-slate-500">(opsional)</span></label>

                            <template x-if="selected.type === 'yes_no'">
                                <select id="fb-default" class="form-control form-control-sm" x-model="selected.default_value">
                                    <option value="">Tanpa nilai bawaan</option>
                                    <option value="1">Ya</option>
                                    <option value="0">Tidak</option>
                                </select>
                            </template>
                            <template x-if="isChoice(selected)">
                                <select id="fb-default" class="form-control form-control-sm" x-model="selected.default_value">
                                    <option value="">Tanpa nilai bawaan</option>
                                    <template x-for="option in selected.options" :key="option.uid">
                                        <option :value="option.value" x-text="option.label" :selected="option.value === selected.default_value"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="selected.type === 'date'">
                                <input id="fb-default" type="date" class="form-control form-control-sm" x-model="selected.default_value">
                            </template>
                            <template x-if="selected.type === 'time'">
                                <input id="fb-default" type="time" class="form-control form-control-sm" x-model="selected.default_value">
                            </template>
                            <template x-if="selected.type === 'datetime'">
                                <input id="fb-default" type="datetime-local" class="form-control form-control-sm" x-model="selected.default_value">
                            </template>
                            <template x-if="selected.type === 'number'">
                                <input id="fb-default" type="number" step="any" class="form-control form-control-sm" x-model="selected.default_value">
                            </template>
                            <template x-if="isText(selected)">
                                <input id="fb-default" type="text" maxlength="255" class="form-control form-control-sm" x-model="selected.default_value">
                            </template>

                            <p class="form-hint">Terisi otomatis saat formulir dibuka; pengisi tetap dapat mengubahnya.</p>
                            <p class="form-error" x-show="error('default_value')" x-text="error('default_value')"></p>
                        </div>
                    </div>
                </template>
            </aside>
        </div>
    </div>
</x-app-layout>
