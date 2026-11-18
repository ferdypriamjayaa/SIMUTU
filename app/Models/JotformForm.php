<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registry of Jotform form definitions known to SIMUTU: which indicator each
 * one is mapped to, and which form version its last import produced.
 * Holds definitions only; Jotform submissions are never read or stored.
 */
class JotformForm extends Model
{
    protected $fillable = [
        'jotform_form_id',
        'title',
        'source_directory',
        'indicator_id',
        'indicator_form_version_id',
        'definition_hash',
        'question_count',
        'imported_at',
        'imported_by',
    ];

    protected function casts(): array
    {
        return [
            'question_count' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(IndicatorFormVersion::class, 'indicator_form_version_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
