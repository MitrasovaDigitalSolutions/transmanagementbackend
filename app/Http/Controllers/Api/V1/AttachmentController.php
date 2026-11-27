<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Crew;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function storeForVehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        return $this->store($request, $vehicle, 'vehicles');
    }

    public function storeForCrew(Request $request, Crew $crew): JsonResponse
    {
        if ($request->input('category') === 'thumbnail') {
            return response()->json([
                'success' => false,
                'message' => 'Crew hanya mendukung lampiran dokumen.',
            ], 422);
        }

        return $this->store($request, $crew, 'crews');
    }

    public function file(Attachment $attachment)
    {
        $disk = Storage::disk($attachment->diskName());

        if (! $disk->exists($attachment->path)) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan.',
            ], 404);
        }

        return $disk->response($attachment->path);
    }

    private function store(Request $request, Model $owner, string $folder): JsonResponse
    {
        $category = (string) $request->input('category');

        $data = $request->validate([
            'category' => $owner instanceof Crew
                ? ['required', 'in:document']
                : ['required', 'in:thumbnail,document'],
            'label' => ['nullable', 'string', 'max:50'],
            'expires_at' => ['nullable', 'date'],
            'file' => $this->fileRules($category),
        ]);

        $file = $request->file('file');
        $disk = $category === 'thumbnail' ? 'public' : 'private';
        // Crew model only exposes documents(); vehicles expose all attachments().
        $attachments = $owner instanceof Crew ? $owner->documents() : $owner->attachments();

        $oldThumbnails = $category === 'thumbnail'
            ? $attachments->where('category', 'thumbnail')->get()
            : collect();

        $path = $file->store("{$folder}/{$owner->getKey()}", $disk);

        $attachment = $attachments->create([
            'category' => $category,
            'label' => $data['label'] ?? null,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        foreach ($oldThumbnails as $old) {
            Storage::disk($old->diskName())->delete($old->path);
            $old->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Lampiran berhasil diunggah.',
            'data' => $attachment,
        ], 201);
    }

    private function fileRules(string $category): array
    {
        if ($category === 'thumbnail') {
            return ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'];
        }

        return ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'];
    }
}
