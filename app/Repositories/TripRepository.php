<?php

namespace App\Repositories;

use App\Models\ActivityLog;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\TripStatusHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class TripRepository
{
    // ----------
    public function findOrFail(int $tripId): Trip
    {
        return Trip::query()->findOrFail($tripId);
    }
    // ----------
    public function findForUpdateOrFail(int $tripId): Trip
    {
        return Trip::query()->whereKey($tripId)->lockForUpdate()->firstOrFail();
    }
    // ----------
    public function findDriverOrFail(int $driverId): Driver
    {
        return Driver::query()->findOrFail($driverId);
    }
    // ----------
    public function findDriverForUpdate(int $driverId): ?Driver
    {
        return Driver::query()->whereKey($driverId)->lockForUpdate()->first();
    }
    // ----------
    public function driverHasAnotherActiveTrip(Driver $driver, Trip $trip): bool
    {
        return Trip::query()
            ->where('driver_id', $driver->id)
            ->where('id', '!=', $trip->id)
            ->whereIn('status', ['assigned', 'driver_arriving', 'in_progress'])
            ->exists();
    }
    // ----------
    public function saveTrip(Trip $trip): void
    {
        $trip->save();
    }
    // ----------
    public function saveDriver(Driver $driver): void
    {
        $driver->save();
    }
    // ----------
    public function createStatusHistory(array $attributes): TripStatusHistory
    {
        return TripStatusHistory::query()->create($attributes);
    }
    // ----------
    public function createActivityLog(array $attributes): ActivityLog
    {
        return ActivityLog::query()->create($attributes);
    }
    // ----------
    public function paginateDispatchTrips(string $search, string $status, string $driverId, int $perPage, int $page): LengthAwarePaginator
    {
        return Trip::query()
            ->select([
                'id',
                'customer_name',
                'pickup_address',
                'dropoff_address',
                'driver_id',
                'status',
                'estimated_fare',
                'version',
            ])
            ->with(['driver:id,name,status'])
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%' . $search . '%';

                $query->where(function ($query) use ($term): void {
                    $query->where('id', 'like', $term)
                        ->orWhere('customer_name', 'like', $term)
                        ->orWhere('pickup_address', 'like', $term)
                        ->orWhere('dropoff_address', 'like', $term)
                        ->orWhereHas('driver', fn ($driverQuery) => $driverQuery->where('name', 'like', $term));
                });
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($driverId !== '', fn ($query) => $query->where('driver_id', $driverId))
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }
    // ----------
    public function findDispatchTrip(int $tripId): ?Trip
    {
        return Trip::query()
            ->with(['driver:id,name,status', 'statusHistory' => fn ($query) => $query->limit(10)->latest('id')])
            ->select(['id', 'customer_name', 'pickup_address', 'dropoff_address', 'driver_id', 'status', 'estimated_fare', 'version'])
            ->find($tripId);
    }
    // ----------
    public function dispatchStatusCounts(): object
    {
        return Trip::query()
            ->selectRaw('SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending')
            ->selectRaw('SUM(CASE WHEN status = "assigned" THEN 1 ELSE 0 END) as assigned')
            ->selectRaw('SUM(CASE WHEN status = "in_progress" THEN 1 ELSE 0 END) as in_progress')
            ->selectRaw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed')
            ->first();
    }
    // ----------
    public function dispatchDrivers(): Collection
    {
        return Driver::query()->select(['id', 'name', 'status'])->orderBy('name')->get();
    }
}