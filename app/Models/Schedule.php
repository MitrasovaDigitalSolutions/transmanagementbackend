<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'schedule_date',
        'status',
        'departure_time',
        'destination',
        'driver_id',
        'kondektur_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'schedule_date' => 'date',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Crew::class, 'driver_id');
    }

    public function kondektur(): BelongsTo
    {
        return $this->belongsTo(Crew::class, 'kondektur_id');
    }
}
