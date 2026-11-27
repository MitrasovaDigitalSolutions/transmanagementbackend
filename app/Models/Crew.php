<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Crew extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'nik',
        'phone',
        'sim_number',
        'sim_type',
        'sim_expires_at',
        'address',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'sim_expires_at' => 'date',
        ];
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')
            ->where('category', 'document');
    }
}
