<?php

namespace App\Services;

use App\Models\Trip;
use App\Repositories\TripRepository;

class DispatchBoardService
{
    private readonly TripRepository $trips;

    public function __construct(TripRepository $trips)
    {
        $this->trips = $trips;
    }
    // ----------
    public function findTripOrFail(int $tripId): Trip
    {
        return $this->trips->findOrFail($tripId);
    }
    // ----------
    public function findDriverOrFail(int $driverId): \App\Models\Driver
    {
        return $this->trips->findDriverOrFail($driverId);
    }
    // ----------
    public function recordConcurrencyConflict(?int $actorId, int $tripId, string $operation, ?int $expectedVersion): ?Trip
    {
        $currentTrip = $this->trips->findDispatchTrip($tripId);

        $this->trips->createActivityLog([
            'actor_id' => $actorId,
            'action' => 'trip.concurrency_conflict',
            'entity_type' => Trip::class,
            'entity_id' => $tripId,
            'previous_values' => [
                'expected_version' => $expectedVersion,
                'operation' => $operation,
            ],
            'new_values' => $currentTrip ? [
                'status' => $currentTrip->status,
                'driver_id' => $currentTrip->driver_id,
                'estimated_fare' => $currentTrip->estimated_fare,
                'version' => $currentTrip->version,
            ] : null,
            'reason' => 'A stale client update was rejected.',
            'created_at' => now(),
        ]);

        return $currentTrip;
    }
    // ----------
    public function dashboardData(string $search, string $status, string $driverId, int $perPage, int $page): array
    {
        return [
            'trips' => $this->trips->paginateDispatchTrips($search, $status, $driverId, $perPage, $page),
            'drivers' => $this->trips->dispatchDrivers(),
            'counts' => $this->trips->dispatchStatusCounts(),
        ];
    }
    // ----------
    public function findDispatchTrip(int $tripId): ?Trip
    {
        return $this->trips->findDispatchTrip($tripId);
    }
}