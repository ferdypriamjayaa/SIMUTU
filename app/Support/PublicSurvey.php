<?php

namespace App\Support;

use App\Models\Indicator;
use App\Models\IndicatorFormVersion;
use App\Models\JotformForm;
use App\Models\Unit;

/**
 * The public patient satisfaction survey: the one form anyone may open and
 * submit without an account (config/survey.php).
 *
 * Only this indicator is ever reachable from the public page. It must be
 * active and have a published form; every answer is recorded for the
 * configured unit, without a user.
 */
class PublicSurvey
{
    /** Shown in reports as "Diinput Oleh" for answers without an account. */
    public const SUBMITTER = 'Publik (survei online)';

    private function __construct(
        public readonly Indicator $indicator,
        public readonly IndicatorFormVersion $version,
        public readonly Unit $unit,
    ) {}

    /**
     * The survey as it can be filled in now, or null when it is not available
     * (not imported, inactive, not published, or the unit is missing).
     */
    public static function current(): ?self
    {
        $indicator = JotformForm::where('jotform_form_id', (string) config('survey.jotform_form_id'))
            ->whereNotNull('indicator_id')
            ->first()?->indicator;

        if ($indicator === null || ! $indicator->is_active) {
            return null;
        }

        $version = $indicator->publishedFormVersion()->with('fields.options')->first();
        $unit = Unit::where('code', (string) config('survey.unit_code'))->where('is_active', true)->first();

        return $version && $unit ? new self($indicator, $version, $unit) : null;
    }
}
