<?php

namespace App\Http\Controllers;

use App\Models\Observation;
use App\Support\ObservationAnswers;
use App\Support\PublicSurvey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public patient satisfaction survey (no login).
 *
 * Opens and accepts only the survey of PublicSurvey: nothing else of SIMUTU
 * is reachable from here, and nothing recorded is ever shown back. The form
 * is the indicator's published Form Builder form, with its conditional
 * questions; the answers are validated by the same rules as the internal
 * observation form and stored as an observation of the configured unit,
 * without a user. CSRF (web middleware), a per-IP rate limit
 * (AppServiceProvider) and a honeypot field keep spam out.
 */
class PublicSurveyController extends Controller
{
    /** Hidden field real visitors never fill in; bots usually do. */
    public const HONEYPOT = 'website';

    public function show(): View|Response
    {
        $survey = PublicSurvey::current();

        if ($survey === null) {
            return response()->view('survey.unavailable', [], 503);
        }

        return view('survey.show', [
            'survey' => $survey,
            'values' => ObservationAnswers::defaults($survey->version),
        ]);
    }

    public function store(Request $request): RedirectResponse|Response
    {
        $survey = PublicSurvey::current();

        if ($survey === null) {
            return response()->view('survey.unavailable', [], 503);
        }

        // A bot: answer as if it worked, store nothing.
        if (filled($request->input(self::HONEYPOT))) {
            return redirect()->route('survey.show')->with('survey_sent', true);
        }

        // The form a visitor filled in was replaced meanwhile: show the current one.
        if ($request->integer('form_version_id') !== $survey->version->id) {
            return redirect()->route('survey.show')->withInput()
                ->with('error', 'Formulir survei baru saja diperbarui. Periksa kembali jawaban Anda, lalu kirim ulang.');
        }

        $input = $request->except(['_token', self::HONEYPOT, 'form_version_id', 'indicator_id', 'unit_id', 'user_id', 'observation_date']);

        try {
            $validated = validator(
                $input,
                ObservationAnswers::rules($survey->version, $input),
                [],
                ObservationAnswers::attributes($survey->version),
            )->validate();
        } catch (ValidationException $exception) {
            return redirect()->route('survey.show')->withInput()->withErrors($exception->errors())
                ->with('error', 'Survei belum terkirim. Lengkapi jawaban yang ditandai.');
        }

        DB::transaction(function () use ($survey, $validated) {
            // Indicator, unit and date are set here, never taken from the request.
            $observation = Observation::create([
                'indicator_id' => $survey->indicator->id,
                'indicator_form_version_id' => $survey->version->id,
                'unit_id' => $survey->unit->id,
                'user_id' => null,
                'observation_date' => now()->toDateString(),
            ]);

            ObservationAnswers::sync($observation, $survey->version, $validated);
        });

        return redirect()->route('survey.show')
            ->with('survey_sent', true)
            ->with('success', 'Terima kasih, survei Anda berhasil terkirim.');
    }
}
