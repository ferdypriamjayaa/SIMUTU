@extends('layouts.public')

@section('title', 'Survei Kepuasan Pasien')

@section('content')
    @if (session('survey_sent'))
        {{-- Thank-you state: nothing that was submitted is shown back. --}}
        <section class="card px-5 py-10 text-center sm:px-8" aria-live="polite" data-survey-sent>
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-accent-50 text-accent-600 dark:bg-accent-500/15 dark:text-accent-300">
                <x-icon name="check-circle" class="h-8 w-8" />
            </span>
            <h1 class="mt-4 text-xl font-bold text-navy-900 dark:text-slate-100">Terima kasih</h1>
            <p class="mt-1.5 text-sm text-gray-600 dark:text-slate-300">Survei Anda berhasil terkirim.</p>
            <a href="{{ route('survey.show') }}" class="btn btn-secondary mt-6 w-full sm:w-auto">Isi survei lagi</a>
        </section>
    @else
        <form method="POST" action="{{ route('survey.store') }}" class="space-y-5" data-survey-form>
            @csrf
            <input type="hidden" name="form_version_id" value="{{ $survey->version->id }}" />

            {{-- Honeypot: invisible to people and screen readers; bots fill it in. --}}
            <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                <label for="{{ \App\Http\Controllers\PublicSurveyController::HONEYPOT }}">Jangan diisi</label>
                <input type="text" id="{{ \App\Http\Controllers\PublicSurveyController::HONEYPOT }}" name="{{ \App\Http\Controllers\PublicSurveyController::HONEYPOT }}" tabindex="-1" autocomplete="off" />
            </div>

            <div class="px-1">
                <h1 class="text-xl font-bold leading-tight text-navy-900 dark:text-slate-100 sm:text-2xl">Survei Kepuasan Pasien</h1>
            </div>

            <section class="card space-y-5 px-4 py-5 sm:space-y-6 sm:px-6 sm:py-6">
                <x-flash />

                @include('observations.partials.fields', [
                    'version' => $survey->version,
                    'values' => $values,
                    'withDate' => false,
                ])
            </section>

            {{-- Always at the end of the form, in the flow of the page (never over it). --}}
            <div class="pb-2">
                <x-button type="submit" icon="check-circle" loading-text="Mengirim…" class="h-12 w-full text-base sm:h-11 sm:text-sm" data-survey-submit>
                    Kirim Survei
                </x-button>
            </div>
        </form>
    @endif
@endsection
