<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => VehicleType::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:vehicle_types,name'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $vehicleType = VehicleType::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Tipe kendaraan berhasil dibuat.',
            'data' => $vehicleType,
        ], 201);
    }

    public function show(VehicleType $vehicleType): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $vehicleType,
        ]);
    }

    public function update(Request $request, VehicleType $vehicleType): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('vehicle_types', 'name')->ignore($vehicleType->id)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $vehicleType->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Tipe kendaraan berhasil diperbarui.',
            'data' => $vehicleType,
        ]);
    }

    public function destroy(VehicleType $vehicleType): JsonResponse
    {
        if ($vehicleType->vehicles()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Tipe kendaraan masih dipakai kendaraan.',
            ], 422);
        }

        $vehicleType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tipe kendaraan berhasil dihapus.',
        ]);
    }
}
