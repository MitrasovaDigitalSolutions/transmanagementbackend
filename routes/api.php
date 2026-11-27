<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CrewController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VehicleController;
use App\Http\Controllers\Api\V1\VehicleTypeController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', function () {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'status' => 'db_unavailable',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'status' => 'ok',
        ]);
    });

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::prefix('vehicle-types')->group(function () {
            Route::get('/', [VehicleTypeController::class, 'index'])->middleware('permission:vehicle-types.view');
            Route::post('/', [VehicleTypeController::class, 'store'])->middleware('permission:vehicle-types.create');
            Route::get('{vehicleType}', [VehicleTypeController::class, 'show'])->middleware('permission:vehicle-types.view');
            Route::put('{vehicleType}', [VehicleTypeController::class, 'update'])->middleware('permission:vehicle-types.update');
            Route::delete('{vehicleType}', [VehicleTypeController::class, 'destroy'])->middleware('permission:vehicle-types.delete');
        });

        Route::prefix('vehicles')->group(function () {
            Route::get('/', [VehicleController::class, 'index'])->middleware('permission:vehicles.view');
            Route::post('/', [VehicleController::class, 'store'])->middleware('permission:vehicles.create');
            Route::get('{vehicle}', [VehicleController::class, 'show'])->middleware('permission:vehicles.view');
            Route::put('{vehicle}', [VehicleController::class, 'update'])->middleware('permission:vehicles.update');
            Route::delete('{vehicle}', [VehicleController::class, 'destroy'])->middleware('permission:vehicles.delete');
            Route::post('{vehicle}/attachments', [AttachmentController::class, 'storeForVehicle'])->middleware('permission:vehicles.update');
        });

        Route::prefix('crews')->group(function () {
            Route::get('/', [CrewController::class, 'index'])->middleware('permission:crews.view');
            Route::post('/', [CrewController::class, 'store'])->middleware('permission:crews.create');
            Route::get('{crew}', [CrewController::class, 'show'])->middleware('permission:crews.view');
            Route::put('{crew}', [CrewController::class, 'update'])->middleware('permission:crews.update');
            Route::delete('{crew}', [CrewController::class, 'destroy'])->middleware('permission:crews.delete');
            Route::post('{crew}/attachments', [AttachmentController::class, 'storeForCrew'])->middleware('permission:crews.update');
        });

        Route::prefix('schedules')->group(function () {
            Route::get('/', [ScheduleController::class, 'index'])->middleware('permission:schedules.view');
            Route::get('daily', [ScheduleController::class, 'daily'])->middleware('permission:schedules.view');
            Route::post('/', [ScheduleController::class, 'store'])->middleware('permission:schedules.create');
            Route::get('{schedule}', [ScheduleController::class, 'show'])->middleware('permission:schedules.view');
            Route::put('{schedule}', [ScheduleController::class, 'update'])->middleware('permission:schedules.update');
            Route::delete('{schedule}', [ScheduleController::class, 'destroy'])->middleware('permission:schedules.delete');
        });

        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index'])->middleware('permission:users.view');
            Route::post('/', [UserController::class, 'store'])->middleware('permission:users.create');
            Route::get('{user}', [UserController::class, 'show'])->middleware('permission:users.view');
            Route::put('{user}', [UserController::class, 'update'])->middleware('permission:users.update');
            Route::delete('{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
        });

        Route::prefix('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->middleware('permission:roles.view');
            Route::post('/', [RoleController::class, 'store'])->middleware('permission:roles.create');
            Route::get('{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
            Route::put('{role}', [RoleController::class, 'update'])->middleware('permission:roles.update');
            Route::delete('{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');
        });

        Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view');

        Route::prefix('media')->group(function () {
            Route::get('/', [MediaController::class, 'index'])->middleware('permission:media.view');
            Route::put('{attachment}', [MediaController::class, 'update'])->middleware('permission:media.update');
            Route::post('{attachment}/replace', [MediaController::class, 'replace'])->middleware('permission:media.update');
            Route::delete('{attachment}', [MediaController::class, 'destroy'])->middleware('permission:media.delete');
        });
    });

    Route::get('attachments/{attachment}/file', [AttachmentController::class, 'file'])
        ->name('attachments.file')
        ->middleware('signed');
});
