<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of an indicator's observation form. Drafts are editable;
 * published and archived versions are immutable so that recorded
 * observations always match the form they were entered on.
 */
class IndicatorFormVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draf',
        self::STATUS_PUBLISHED => 'Terbit',
        self::STATUS_ARCHIVED => 'Arsip',
    ];

    protected $fillable = [
        'indicator_id',
        'version',
        'status',
        'published_at',
        'archived_at',
        'created_by',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(IndicatorField::class)->orderBy('sort_order')->orderBy('id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Fields shown on the observation form, in display order.
     *
     * @return Collection<int, IndicatorField>
     */
    public function activeFields(): Collection
    {
        return $this->fields->where('is_active', true)->values();
    }
}
