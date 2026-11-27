<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_type_id',
        'code',
        'name',
        'plate_number',
        'seat_capacity',
        'year',
        'status',
        'notes',
    ];

    protected $appends = ['thumbnail_url'];

    protected function casts(): array
    {
        return [
            'seat_capacity' => 'integer',
            'year' => 'integer',
        ];
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function thumbnail(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')
            ->where('category', 'thumbnail');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')
            ->where('category', 'document');
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail?->url;
    }
}
