<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\StaleVersionException;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\TripRepository;
use Illuminate\Support\Facades\DB;

class TripFareService
{
    private readonly TripRepository $trips;

    public function __construct(TripRepository $trips)
    {
        $this->trips = $trips;
    }
    // ----------
    public function updateFare(int $tripId, float $fare, User $actor, ?int $expectedVersion = null): Trip
    {
        if ($actor->role !== 'supervisor') {
            throw new AuthorizationException('Only supervisors can change the fare.');
        }

        return DB::transaction(function () use ($tripId, $fare, $actor, $expectedVersion): Trip {
            $lockedTrip = $this->trips->findForUpdateOrFail($tripId);

            if ($expectedVersion !== null && $lockedTrip->version !== $expectedVersion) {
                throw new StaleVersionException('This trip was updated by someone else. Please refresh and try again.');
            }

            if ($lockedTrip->status !== 'pending') {
                throw new \RuntimeException('Fare can only be changed while the trip is pending.');
            }

            $previous = $lockedTrip->only(['estimated_fare', 'version']);
            $lockedTrip->estimated_fare = $fare;
            $lockedTrip->version = (int) $lockedTrip->version + 1;
            $this->trips->saveTrip($lockedTrip);

            $this->trips->createActivityLog([
                'actor_id' => $actor->id,
                'action' => 'trip.fare_updated',
                'entity_type' => Trip::class,
                'entity_id' => $lockedTrip->id,
                'previous_values' => $previous,
                'new_values' => $lockedTrip->only(['estimated_fare', 'version']),
                'reason' => null,
                'created_at' => now(),
            ]);

            return $lockedTrip;
        });
    }
}