<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Crew;
use App\Models\Schedule;
use App\Models\Vehicle;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'status' => ['nullable', Rule::in(['ready', 'in_use', 'maintenance', 'off'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $perPage = max(1, min((int) $request->input('per_page', 20), 100));

        $schedules = Schedule::query()
            ->with(['vehicle.vehicleType', 'driver', 'kondektur'])
            ->when($validated['vehicle_id'] ?? null, fn ($query, $vehicleId) => $query->where('vehicle_id', $vehicleId))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['from'] ?? null, fn ($query, $from) => $query->whereDate('schedule_date', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, $to) => $query->whereDate('schedule_date', '<=', $to))
            ->orderBy('schedule_date')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $schedules->items(),
            'meta' => [
                'current_page' => $schedules->currentPage(),
                'last_page' => $schedules->lastPage(),
                'per_page' => $schedules->perPage(),
                'total' => $schedules->total(),
            ],
        ]);
    }

    public function daily(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $date = Carbon::parse($validated['date'])->toDateString();

        $vehicles = Vehicle::query()
            ->with(['vehicleType', 'thumbnail'])
            ->orderBy('code')
            ->get();

        $schedules = Schedule::query()
            ->with(['driver', 'kondektur'])
            ->whereDate('schedule_date', $date)
            ->get()
            ->keyBy('vehicle_id');

        $items = $vehicles->map(function (Vehicle $vehicle) use ($schedules) {
            $schedule = $schedules->get($vehicle->id);

            return [
                'vehicle' => $vehicle,
                'schedule' => $schedule,
                'status' => $schedule?->status ?? 'ready',
                'is_recorded' => $schedule !== null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items->values(),
            'meta' => [
                'date' => $date,
                'total' => $vehicles->count(),
                'ready' => $items->where('status', 'ready')->count(),
                'recorded' => $schedules->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'schedule_date' => ['nullable', 'date', 'prohibits:start_date,end_date', 'required_without_all:start_date,end_date'],
            'start_date' => ['nullable', 'date', 'prohibits:schedule_date', 'required_without:schedule_date', 'required_with:end_date'],
            'end_date' => ['nullable', 'date', 'prohibits:schedule_date', 'required_with:start_date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(['ready', 'in_use', 'maintenance', 'off'])],
            'departure_time' => ['nullable', 'date_format:H:i'],
            'destination' => ['nullable', 'string', 'max:150'],
            'driver_id' => ['nullable', 'integer', 'exists:crews,id'],
            'kondektur_id' => ['nullable', 'integer', 'exists:crews,id', 'different:driver_id'],
            'notes' => ['nullable', 'string'],
        ]);

        $vehicleId = (int) $validated['vehicle_id'];
        $driverId = $validated['driver_id'] ?? null;
        $kondekturId = $validated['kondektur_id'] ?? null;

        $this->validateCrewTypes($driverId, $kondekturId);

        $dates = [];
        if (! empty($validated['schedule_date'])) {
            $dates[] = Carbon::parse($validated['schedule_date'])->toDateString();
        } else {
            foreach (CarbonPeriod::create($validated['start_date'], $validated['end_date']) as $date) {
                $dates[] = $date->toDateString();
            }
        }

        $data = [
            'status' => $validated['status'] ?? 'ready',
            'departure_time' => $validated['departure_time'] ?? null,
            'destination' => $validated['destination'] ?? null,
            'driver_id' => $driverId,
            'kondektur_id' => $kondekturId,
            'notes' => $validated['notes'] ?? null,
        ];

        $saved = DB::transaction(function () use ($vehicleId, $driverId, $kondekturId, $dates, $data) {
            $saved = [];

            foreach ($dates as $date) {
                $crewIds = array_values(array_filter([$driverId, $kondekturId], fn ($id) => $id !== null));

                if ($crewIds) {
                    $conflict = Schedule::query()
                        ->where('vehicle_id', '!=', $vehicleId)
                        ->whereDate('schedule_date', $date)
                        ->where(function ($query) use ($crewIds) {
                            $query->whereIn('driver_id', $crewIds)
                                ->orWhereIn('kondektur_id', $crewIds);
                        })
                        ->exists();

                    if ($conflict) {
                        throw ValidationException::withMessages([
                            'schedule_date' => "Crew sudah bertugas di kendaraan lain pada tanggal {$date}.",
                        ]);
                    }
                }

                $saved[] = Schedule::updateOrCreate(
                    ['vehicle_id' => $vehicleId, 'schedule_date' => Carbon::parse($date)],
                    $data
                );
            }

            return $saved;
        });

        return response()->json([
            'success' => true,
            'message' => 'Jadwal berhasil disimpan.',
            'data' => $saved,
            'meta' => ['saved' => count($saved)],
        ], 201);
    }

    public function show(Schedule $schedule): JsonResponse
    {
        $schedule->load(['vehicle.vehicleType', 'driver', 'kondektur']);

        return response()->json([
            'success' => true,
            'data' => $schedule,
        ]);
    }

    public function update(Request $request, Schedule $schedule): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['sometimes', 'required', 'integer', 'exists:vehicles,id'],
            'schedule_date' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', Rule::in(['ready', 'in_use', 'maintenance', 'off'])],
            'departure_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'destination' => ['sometimes', 'nullable', 'string', 'max:150'],
            'driver_id' => ['sometimes', 'nullable', 'integer', 'exists:crews,id'],
            'kondektur_id' => ['sometimes', 'nullable', 'integer', 'exists:crews,id', 'different:driver_id'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $vehicleId = (int) ($validated['vehicle_id'] ?? $schedule->vehicle_id);
        $date = Carbon::parse($validated['schedule_date'] ?? $schedule->schedule_date)->toDateString();
        $driverId = array_key_exists('driver_id', $validated) ? $validated['driver_id'] : $schedule->driver_id;
        $kondekturId = array_key_exists('kondektur_id', $validated) ? $validated['kondektur_id'] : $schedule->kondektur_id;

        if ($driverId !== null && $kondekturId !== null && (int) $driverId === (int) $kondekturId) {
            throw ValidationException::withMessages([
                'kondektur_id' => 'Driver dan kondektur tidak boleh orang yang sama.',
            ]);
        }

        $this->validateCrewTypes($driverId, $kondekturId);

        $duplicate = Schedule::query()
            ->where('vehicle_id', $vehicleId)
            ->whereDate('schedule_date', $date)
            ->whereKeyNot($schedule->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'schedule_date' => 'Jadwal untuk kendaraan ini pada tanggal tersebut sudah ada.',
            ]);
        }

        $crewIds = array_values(array_filter([$driverId, $kondekturId], fn ($id) => $id !== null));

        if ($crewIds) {
            $conflict = Schedule::query()
                ->whereKeyNot($schedule->id)
                ->where('vehicle_id', '!=', $vehicleId)
                ->whereDate('schedule_date', $date)
                ->where(function ($query) use ($crewIds) {
                    $query->whereIn('driver_id', $crewIds)
                        ->orWhereIn('kondektur_id', $crewIds);
                })
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'schedule_date' => "Crew sudah bertugas di kendaraan lain pada tanggal {$date}.",
                ]);
            }
        }

        $schedule->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Jadwal berhasil diperbarui.',
            'data' => $schedule->fresh()->load(['vehicle.vehicleType', 'driver', 'kondektur']),
        ]);
    }

    public function destroy(Schedule $schedule): JsonResponse
    {
        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Jadwal berhasil dihapus.',
        ]);
    }

    private function validateCrewTypes(?int $driverId, ?int $kondekturId): void
    {
        if ($driverId !== null) {
            $driver = Crew::find($driverId);
            if ($driver && $driver->type !== 'driver') {
                throw ValidationException::withMessages([
                    'driver_id' => 'Crew yang dipilih harus bertipe driver.',
                ]);
            }
        }

        if ($kondekturId !== null) {
            $kondektur = Crew::find($kondekturId);
            if ($kondektur && $kondektur->type !== 'kondektur') {
                throw ValidationException::withMessages([
                    'kondektur_id' => 'Crew yang dipilih harus bertipe kondektur.',
                ]);
            }
        }
    }
}
