<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Attachment::query()->with('attachable');

        if ($q = $request->input('q')) {
            $query->where(function ($inner) use ($q) {
                $inner->whereLike('label', "%{$q}%", caseSensitive: false)
                    ->orWhereLike('original_name', "%{$q}%", caseSensitive: false);
            });
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($attachableType = $request->input('attachable_type')) {
            $query->where('attachable_type', $attachableType);
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $page = $query->latest('id')->paginate($perPage);

        $data = $page->getCollection()->map(fn (Attachment $attachment) => array_merge(
            $attachment->toArray(),
            ['owner' => [
                'type' => $attachment->attachable_type,
                'id' => $attachment->attachable_id,
                'name' => $attachment->attachable?->name,
            ]]
        ));

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function update(Request $request, Attachment $attachment): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $attachment->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Media berhasil diperbarui.',
            'data' => $attachment,
        ]);
    }

    public function replace(Request $request, Attachment $attachment): JsonResponse
    {
        $request->validate([
            'file' => $attachment->category === 'thumbnail'
                ? ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120']
                : ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $file = $request->file('file');
        $disk = Storage::disk($attachment->diskName());
        $path = $file->store(dirname($attachment->path), $attachment->diskName());

        $disk->delete($attachment->path);

        $attachment->update([
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'File media berhasil diganti.',
            'data' => $attachment,
        ]);
    }

    public function destroy(Attachment $attachment): JsonResponse
    {
        Storage::disk($attachment->diskName())->delete($attachment->path);
        $attachment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Media berhasil dihapus.',
        ]);
    }
}
