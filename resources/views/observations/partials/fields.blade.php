@php
    use App\Models\IndicatorField;

    /*
     * Fields of one form version, rendered from its definition.
     *   $version  IndicatorFormVersion with fields.options loaded
     *   $values   current values by field key (defaults on create, stored answers on edit)
     *   $date     observation date (Y-m-d) to prefill
     *   $employees  HRIS employee dropdowns by field key (App\Support\EmployeePicker::forForm)
     *   $doctors  Doctor API dropdowns by field key (App\Support\DoctorPicker::forForm)
     *   $lookup   extra query for the dropdown searches (['observation' => id] when editing)
     *   $withDate false on the public survey: the server sets the date itself
     */
    $values ??= [];
    $employees ??= [];
    $doctors ??= [];
    $lookup ??= [];
    $date ??= now()->format('Y-m-d');
    $withDate ??= true;

    // Consecutive fields that share a section title form one group.
    $groups = [];
    foreach ($version->activeFields() as $field) {
        $last = array_key_last($groups);
        if ($last !== null && $groups[$last]['title'] === $field->section) {
            $groups[$last]['fields'][] = $field;
        } else {
            $groups[] = ['title' => $field->section, 'fields' => [$field]];
        }
    }

    $wide = [IndicatorField::TYPE_TEXTAREA, IndicatorField::TYPE_RADIO, IndicatorField::TYPE_CHECKBOX];

    // Conditional fields (e.g. only for Rawat Inap) start shown or hidden as the
    // current answers say, also after a failed save; resources/js/conditional-fields.js
    // keeps them in step while the form is filled in.
    $answers = [];
    foreach ($version->activeFields() as $field) {
        $answers[$field->key] = old($field->key, $values[$field->key] ?? null);
    }
    $visible = fn (IndicatorField $field) => $field->isVisibleFor($answers);
    $condition = fn (IndicatorField $field) => $field->isConditional()
        ? json_encode(['key' => $field->visible_when_key, 'values' => array_map('strval', $field->visible_when_values)])
        : null;
@endphp

@if ($withDate)
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <x-form.field label="Tanggal Observasi" for="observation_date" required>
            <x-form.input type="date" name="observation_date" :value="$date" max="{{ now()->format('Y-m-d') }}" required />
        </x-form.field>
    </div>
@endif

@foreach ($groups as $group)
    <fieldset class="space-y-4" data-section @if (collect($group['fields'])->every(fn ($f) => ! $visible($f))) hidden @endif>
        @if ($group['title'])
            <legend class="mb-4 flex w-full items-center gap-3 text-sm font-semibold text-navy-900 dark:text-slate-100">
                <span class="h-4 w-1 rounded-full bg-brand-500"></span>
                {{ $group['title'] }}
            </legend>
        @endif

        <div @class(['grid grid-cols-1 gap-4', 'sm:grid-cols-2' => count($group['fields']) > 1])>
            @foreach ($group['fields'] as $field)
                @php
                    $key = $field->key;
                    $required = $field->is_required;
                    $current = old($key, $values[$key] ?? null);
                    $boxed = in_array($field->type, [IndicatorField::TYPE_YES_NO, IndicatorField::TYPE_RADIO, IndicatorField::TYPE_CHECKBOX], true);
                    $hasError = $errors->has($key) || $errors->has($key.'.*');
                    $shown = $visible($field);
                @endphp

                @if ($boxed)
                    <div @class([
                            'rounded-xl border bg-gray-50/60 dark:bg-slate-800/40 p-4',
                            'sm:col-span-2' => in_array($field->type, $wide, true),
                            'border-red-300 dark:border-red-500/50' => $hasError,
                            'border-gray-200 dark:border-slate-800' => ! $hasError,
                        ])
                        role="{{ $field->type === IndicatorField::TYPE_CHECKBOX ? 'group' : 'radiogroup' }}" aria-labelledby="{{ $key }}-label"
                        data-field="{{ $key }}" @if ($field->isConditional()) data-visible-when="{{ $condition($field) }}" @endif @unless ($shown) hidden @endunless>
                        <p id="{{ $key }}-label" @class(['form-label', 'form-label-required' => $required])>
                            {{ $field->label }}
                            @unless ($required)
                                <span class="font-normal text-gray-400 dark:text-slate-500">(opsional)</span>
                            @endunless
                        </p>

                        @if ($field->type === IndicatorField::TYPE_YES_NO)
                            <div class="flex gap-2">
                                {{-- A list, not ['1' => …]: PHP turns numeric string keys into integers. --}}
                                @foreach ([['1', 'Ya', true], ['0', 'Tidak', false]] as [$value, $text, $isYes])
                                    <label class="choice {{ $isYes ? 'choice-yes' : 'choice-no' }}">
                                        <input type="radio" name="{{ $key }}" value="{{ $value }}"
                                            @checked($current !== null && (string) $current === $value) @required($required)
                                            class="sr-only" />
                                        <x-icon :name="$isYes ? 'check-circle' : 'x-circle'" class="h-4 w-4" />
                                        {{ $text }}
                                    </label>
                                @endforeach
                            </div>
                        @elseif ($field->type === IndicatorField::TYPE_RADIO)
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach ($field->options as $option)
                                    <label class="choice-option">
                                        <input type="radio" name="{{ $key }}" value="{{ $option->value }}"
                                            @checked($current !== null && (string) $current === $option->value) @required($required)
                                            class="h-4 w-4 shrink-0 border-gray-300 text-brand-600 focus:ring-brand-500/30" />
                                        {{ $option->label }}
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach ($field->options as $option)
                                    <label class="choice-option">
                                        <input type="checkbox" name="{{ $key }}[]" value="{{ $option->value }}"
                                            @checked(in_array($option->value, array_map('strval', (array) $current), true))
                                            class="form-checkbox-base shrink-0" />
                                        {{ $option->label }}
                                    </label>
                                @endforeach
                            </div>
                        @endif

                        @if ($field->help_text)
                            <p class="form-hint">{{ $field->help_text }}</p>
                        @endif
                        <x-input-error :messages="$errors->get($key) ?: \Illuminate\Support\Arr::flatten($errors->get($key.'.*'))" />
                    </div>
                @else
                    <x-form.field :label="$field->label" :for="$key" :required="$required" :optional="! $required" :hint="$field->help_text"
                        :class="in_array($field->type, $wide, true) ? 'sm:col-span-2' : ''"
                        :data-field="$key" :data-visible-when="$condition($field)" :hidden="! $shown">
                        @if ($field->type === IndicatorField::TYPE_TEXTAREA)
                            <x-form.textarea :name="$key" :value="$current" maxlength="{{ $field->maxLength() }}"
                                :placeholder="$field->placeholder" :required="$required" />
                        @elseif ($field->type === IndicatorField::TYPE_NUMBER)
                            <x-form.input type="number" :name="$key" :value="$current"
                                :min="$field->min_value !== null ? \App\Support\ObservationAnswers::number($field->min_value) : null"
                                :max="$field->max_value !== null ? \App\Support\ObservationAnswers::number($field->max_value) : null"
                                step="{{ $field->allow_decimal ? 'any' : '1' }}"
                                inputmode="{{ $field->allow_decimal ? 'decimal' : 'numeric' }}"
                                :placeholder="$field->placeholder" :required="$required" />
                        @elseif ($field->type === IndicatorField::TYPE_DATE)
                            <x-form.input type="date" :name="$key" :value="$current" :required="$required" />
                        @elseif ($field->type === IndicatorField::TYPE_TIME)
                            <x-form.input type="time" :name="$key" :value="$current" :required="$required" />
                        @elseif ($field->type === IndicatorField::TYPE_DATETIME)
                            <x-form.input type="datetime-local" :name="$key" :value="$current" :required="$required" />
                        @elseif ($field->type === IndicatorField::TYPE_SELECT && $field->options->count() >= IndicatorField::SEARCHABLE_MIN_OPTIONS)
                            {{-- Long list (rooms, names…): searchable; the options are already part of the form. --}}
                            @php
                                $chosenOption = $current === null ? null : $field->options->first(fn ($o) => (string) $current === $o->value);
                            @endphp
                            <x-form.combobox :name="$key" :required="$required"
                                :options="$field->options->map(fn ($o) => ['value' => $o->value, 'label' => $o->label])->all()"
                                :selected="$chosenOption ? ['value' => $chosenOption->value, 'label' => $chosenOption->label] : null"
                                :placeholder="$field->placeholder ?: 'Pilih…'" search-placeholder="Cari {{ \Illuminate\Support\Str::lower($field->label) }}…" />
                        @elseif ($field->type === IndicatorField::TYPE_SELECT)
                            <x-form.select :name="$key" :required="$required">
                                <option value="">{{ $field->placeholder ?: 'Pilih…' }}</option>
                                @foreach ($field->options as $option)
                                    <option value="{{ $option->value }}" @selected($current !== null && (string) $current === $option->value)>
                                        {{ $option->label }}
                                    </option>
                                @endforeach
                            </x-form.select>
                        @elseif (isset($doctors[$key]) && $doctors[$key]['groups'])
                            @php
                                $picker = $key.\App\Support\DoctorPicker::SUFFIX;
                                $manual = \App\Support\DoctorPicker::MANUAL;
                                $list = collect($doctors[$key]['groups'])->flatten(1);
                                // Re-select after a failed save, or match the stored name on edit.
                                $chosen = old($picker);
                                if ($chosen === null && filled($current)) {
                                    $match = $list->first(fn ($d) => mb_strtolower($d['name']) === mb_strtolower(trim((string) $current)));
                                    $chosen = $match['id'] ?? $manual;
                                }
                                $chosenDoctor = $chosen === $manual ? ['value' => $manual, 'label' => 'Lainnya — ketik manual']
                                    : (($d = $list->firstWhere('id', $chosen)) ? ['value' => $d['id'], 'label' => $d['name']] : null);
                            @endphp
                            {{-- Searched on the server: the page does not carry the doctor list. --}}
                            <x-form.combobox :name="$picker" :id="$key" :error="$key" :required="$required"
                                :url="route('observations.lookup', 'doctors')" :params="$lookup"
                                :selected="$chosenDoctor" :manual="['value' => $manual, 'label' => 'Lainnya — ketik manual']"
                                :placeholder="$field->placeholder ?: 'Pilih dokter…'" search-placeholder="Cari nama atau spesialisasi dokter…"
                                empty-text="Tidak ada dokter aktif.">
                                {{-- Only submitted when typing manually; otherwise the server fills in the chosen name. --}}
                                <div x-show="isManual" @if ($chosen !== $manual) x-cloak @endif>
                                    <x-form.input :name="$key" :id="$key.'_manual'" :value="$chosen === $manual ? $current : null" maxlength="{{ $field->maxLength() }}"
                                        placeholder="Ketik nama dokter" x-bind:disabled="! isManual" x-bind:required="isManual && @js($required)"
                                        data-combobox-manual aria-label="Nama dokter (manual)" />
                                </div>
                            </x-form.combobox>
                        @elseif (isset($employees[$key]) && $employees[$key]['employees'])
                            @php
                                $picker = $key.\App\Support\EmployeePicker::SUFFIX;
                                $manual = \App\Support\EmployeePicker::MANUAL;
                                $list = collect($employees[$key]['employees']);
                                // Re-select after a failed save, or match the stored name on edit.
                                $chosen = old($picker);
                                if ($chosen === null && filled($current)) {
                                    $match = $list->first(fn ($e) => mb_strtolower($e['name']) === mb_strtolower(trim((string) $current)));
                                    $chosen = $match['id'] ?? $manual;
                                }
                                $chosenEmployee = $chosen === $manual ? ['value' => $manual, 'label' => 'Lainnya — ketik manual']
                                    : (($e = $list->firstWhere('id', $chosen)) ? ['value' => $e['id'], 'label' => $e['name']] : null);
                            @endphp
                            {{-- Searched on the server: the page does not carry the unit's employee list. --}}
                            <x-form.combobox :name="$picker" :id="$key" :error="$key" :required="$required"
                                :url="route('observations.lookup', 'employees')" :params="$lookup"
                                :selected="$chosenEmployee" :manual="['value' => $manual, 'label' => 'Lainnya — ketik manual']"
                                :placeholder="$field->placeholder ?: 'Pilih petugas…'" search-placeholder="Cari nama petugas…"
                                empty-text="Tidak ada karyawan aktif di unit ini.">
                                {{-- Only submitted when typing manually; otherwise the server fills in the chosen name. --}}
                                <div x-show="isManual" @if ($chosen !== $manual) x-cloak @endif>
                                    <x-form.input :name="$key" :id="$key.'_manual'" :value="$chosen === $manual ? $current : null" maxlength="{{ $field->maxLength() }}"
                                        placeholder="Ketik nama petugas" x-bind:disabled="! isManual" x-bind:required="isManual && @js($required)"
                                        data-combobox-manual aria-label="Nama petugas (manual)" />
                                </div>
                            </x-form.combobox>
                        @else
                            <x-form.input :name="$key" :value="$current" maxlength="{{ $field->maxLength() }}"
                                :minlength="$field->min_length ?: null" :placeholder="$field->placeholder" :required="$required" />
                            @if (isset($employees[$key]['notice']))
                                <p class="form-hint">{{ $employees[$key]['notice'] }}</p>
                            @endif
                            @if (isset($doctors[$key]['notice']))
                                <p class="form-hint">{{ $doctors[$key]['notice'] }}</p>
                            @endif
                        @endif
                    </x-form.field>
                @endif
            @endforeach
        </div>
    </fieldset>
@endforeach
