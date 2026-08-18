<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\StaleVersionException;
use App\Exceptions\TransitionException;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\TripRepository;
use Illuminate\Support\Facades\DB;

class TripLifecycleService
{
    private readonly TripRepository $trips;

    // ----------
    private const VALID_TRANSITIONS = [
        'pending' => ['assigned', 'cancelled'],
        'assigned' => ['driver_arriving', 'cancelled'],
        'driver_arriving' => ['in_progress', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];
    // ----------
    public function __construct(TripRepository $trips)
    {
        $this->trips = $trips;
    }
    // ----------
    public function transition(Trip $trip, string $newStatus, User $actor, ?int $expectedVersion = null): Trip
    {
        $this->authorizeAction($actor, $newStatus);

        if (! in_array($newStatus, ['pending', 'assigned', 'driver_arriving', 'in_progress', 'completed', 'cancelled'], true)) {
            throw new TransitionException('Unsupported trip status transition.');
        }

        return DB::transaction(function () use ($trip, $newStatus, $actor, $expectedVersion): Trip {
            $lockedTrip = $this->trips->findForUpdateOrFail($trip->getKey());

            if ($expectedVersion !== null && $lockedTrip->version !== $expectedVersion) {
                throw new StaleVersionException('This trip was updated by someone else. Please refresh and try again.');
            }

            $allowedTransitions = self::VALID_TRANSITIONS[$lockedTrip->status] ?? [];

            if (! in_array($newStatus, $allowedTransitions, true)) {
                throw new TransitionException(sprintf(
                    'Trip status cannot change from %s to %s.',
                    $lockedTrip->status,
                    $newStatus,
                ));
            }

            $previousStatus = $lockedTrip->status;
            $previousDriverId = $lockedTrip->driver_id;

            $lockedTrip->status = $newStatus;
            $lockedTrip->version = (int) $lockedTrip->version + 1;
            $this->trips->saveTrip($lockedTrip);

            if (in_array($newStatus, ['completed', 'cancelled'], true) && $previousDriverId) {
                $driver = $this->trips->findDriverForUpdate($previousDriverId);

                if ($driver) {
                    $driver->status = 'available';
                    $this->trips->saveDriver($driver);
                }
            }

            $this->trips->createStatusHistory([
                'trip_id' => $lockedTrip->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'changed_by' => $actor->id,
                'metadata' => [
                    'action' => 'trip.status_changed',
                    'source' => 'trip_lifecycle_service',
                    'previous_driver_id' => $previousDriverId,
                    'new_status' => $newStatus,
                ],
                'created_at' => now(),
            ]);

            $this->trips->createActivityLog([
                'actor_id' => $actor->id,
                'action' => 'trip.status_changed',
                'entity_type' => Trip::class,
                'entity_id' => $lockedTrip->id,
                'previous_values' => [
                    'status' => $previousStatus,
                    'driver_id' => $previousDriverId,
                    'version' => $lockedTrip->version - 1,
                ],
                'new_values' => [
                    'status' => $newStatus,
                    'driver_id' => $lockedTrip->driver_id,
                    'version' => $lockedTrip->version,
                ],
                'reason' => sprintf('Transitioned from %s to %s', $previousStatus, $newStatus),
                'created_at' => now(),
            ]);

            return $lockedTrip->fresh();
        });
    }
    // ----------
    private function authorizeAction(User $actor, string $newStatus): void
    {
        if (! $actor->can_dispatch) {
            throw new AuthorizationException('You do not have permission to change trip status.');
        }

        if ($actor->role !== 'supervisor') {
            throw new AuthorizationException('Only supervisors can change trip status.');
        }
    }
}
