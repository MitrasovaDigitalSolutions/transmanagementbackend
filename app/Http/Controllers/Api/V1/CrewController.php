<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Crew;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CrewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['driver', 'kondektur'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $perPage = max(1, min((int) $request->input('per_page', 20), 100));

        $crews = Crew::query()
            ->when($validated['q'] ?? null, function ($query, string $q) {
                $query->where(function ($query) use ($q) {
                    $query->whereLike('name', "%{$q}%", caseSensitive: false)
                        ->orWhereLike('nik', "%{$q}%", caseSensitive: false)
                        ->orWhereLike('phone', "%{$q}%", caseSensitive: false);
                });
            })
            ->when($validated['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $crews->items(),
            'meta' => [
                'current_page' => $crews->currentPage(),
                'last_page' => $crews->lastPage(),
                'per_page' => $crews->perPage(),
                'total' => $crews->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['driver', 'kondektur'])],
            'nik' => ['nullable', 'digits:16', Rule::unique('crews', 'nik')],
            'phone' => ['nullable', 'string', 'max:30'],
            'sim_number' => ['nullable', 'string', 'max:30', 'required_if:type,driver'],
            'sim_type' => ['nullable', Rule::in(['A', 'B1', 'B2', 'C']), 'required_if:type,driver'],
            'sim_expires_at' => ['nullable', 'date', 'required_if:type,driver'],
            'address' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $validated['status'] ??= 'active';

        $crew = Crew::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Crew berhasil dibuat.',
            'data' => $crew,
        ], 201);
    }

    public function show(Request $request, Crew $crew): JsonResponse
    {
        if ($request->user()->can('media.view')) {
            $crew->load('documents');
        }

        return response()->json([
            'success' => true,
            'data' => $crew,
        ]);
    }

    public function update(Request $request, Crew $crew): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'type' => ['sometimes', 'required', Rule::in(['driver', 'kondektur'])],
            'nik' => ['sometimes', 'nullable', 'digits:16', Rule::unique('crews', 'nik')->ignore($crew->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'sim_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'sim_type' => ['sometimes', 'nullable', Rule::in(['A', 'B1', 'B2', 'C'])],
            'sim_expires_at' => ['sometimes', 'nullable', 'date'],
            'address' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ]);

        $type = $validated['type'] ?? $crew->type;

        if ($type === 'driver') {
            $simNumber = array_key_exists('sim_number', $validated) ? $validated['sim_number'] : $crew->sim_number;
            $simType = array_key_exists('sim_type', $validated) ? $validated['sim_type'] : $crew->sim_type;
            $simExpiresAt = array_key_exists('sim_expires_at', $validated) ? $validated['sim_expires_at'] : $crew->sim_expires_at;

            $errors = [];
            if (empty($simNumber)) {
                $errors['sim_number'] = 'Nomor SIM wajib diisi untuk driver.';
            }
            if (empty($simType)) {
                $errors['sim_type'] = 'Tipe SIM wajib diisi untuk driver.';
            }
            if (empty($simExpiresAt)) {
                $errors['sim_expires_at'] = 'Masa berlaku SIM wajib diisi untuk driver.';
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
        }

        $crew->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Crew berhasil diperbarui.',
            'data' => $crew,
        ]);
    }

    public function destroy(Crew $crew): JsonResponse
    {
        $used = Schedule::query()
            ->where('driver_id', $crew->id)
            ->orWhere('kondektur_id', $crew->id)
            ->exists();

        if ($used) {
            return response()->json([
                'success' => false,
                'message' => 'Crew tidak bisa dihapus karena masih dipakai di jadwal.',
            ], 422);
        }

        foreach ($crew->documents()->get() as $attachment) {
            Storage::disk($attachment->diskName())->delete($attachment->path);
            $attachment->delete();
        }

        $crew->delete();

        return response()->json([
            'success' => true,
            'message' => 'Crew berhasil dihapus.',
        ]);
    }
}
