<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class Attachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'label',
        'path',
        'original_name',
        'mime_type',
        'size',
        'expires_at',
    ];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'expires_at' => 'date',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function diskName(): string
    {
        return $this->category === 'thumbnail' ? 'public' : 'private';
    }

    public function getUrlAttribute(): ?string
    {
        if (! $this->path) {
            return null;
        }

        if ($this->category === 'thumbnail') {
            return Storage::disk('public')->url($this->path);
        }

        return URL::temporarySignedRoute(
            'attachments.file',
            now()->addMinutes(30),
            ['attachment' => $this->getKey()]
        );
    }
}
