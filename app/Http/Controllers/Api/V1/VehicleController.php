<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Vehicle::query()->with(['vehicleType', 'thumbnail']);

        if ($q = $request->input('q')) {
            $query->where(function ($inner) use ($q) {
                $inner->whereLike('code', "%{$q}%", caseSensitive: false)
                    ->orWhereLike('name', "%{$q}%", caseSensitive: false)
                    ->orWhereLike('plate_number', "%{$q}%", caseSensitive: false);
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($vehicleTypeId = $request->input('vehicle_type_id')) {
            $query->where('vehicle_type_id', $vehicleTypeId);
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $page = $query->latest('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_type_id' => ['required', 'exists:vehicle_types,id'],
            'code' => ['required', 'string', 'max:30', 'unique:vehicles,code'],
            'name' => ['required', 'string', 'max:100'],
            'plate_number' => ['required', 'string', 'max:20', 'unique:vehicles,plate_number'],
            'seat_capacity' => ['required', 'integer', 'min:1'],
            'year' => ['nullable', 'integer', 'between:1900,'.now()->addYear()->year],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
            'notes' => ['nullable', 'string'],
        ]);

        $vehicle = Vehicle::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Kendaraan berhasil dibuat.',
            'data' => $vehicle->load('vehicleType', 'thumbnail'),
        ], 201);
    }

    public function show(Request $request, Vehicle $vehicle): JsonResponse
    {
        $vehicle->load('vehicleType', 'thumbnail');

        $attachments = $request->user()->can('media.view')
            ? $vehicle->attachments()->latest('id')->get()
            : $vehicle->attachments()->where('category', 'thumbnail')->get();

        return response()->json([
            'success' => true,
            'data' => array_merge($vehicle->toArray(), ['attachments' => $attachments]),
        ]);
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'vehicle_type_id' => ['sometimes', 'required', 'exists:vehicle_types,id'],
            'code' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('vehicles', 'code')->ignore($vehicle->id)],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'plate_number' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('vehicles', 'plate_number')->ignore($vehicle->id)],
            'seat_capacity' => ['sometimes', 'required', 'integer', 'min:1'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:1900,'.now()->addYear()->year],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $vehicle->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Kendaraan berhasil diperbarui.',
            'data' => $vehicle->load('vehicleType', 'thumbnail'),
        ]);
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        if ($vehicle->schedules()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Kendaraan sudah dipakai di jadwal.',
            ], 422);
        }

        foreach ($vehicle->attachments()->get() as $attachment) {
            Storage::disk($attachment->diskName())->delete($attachment->path);
            $attachment->delete();
        }

        $vehicle->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kendaraan berhasil dihapus.',
        ]);
    }
}
