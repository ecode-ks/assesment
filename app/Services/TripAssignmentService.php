<?php

namespace App\Services;

use App\Exceptions\AssignmentException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\StaleVersionException;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\TripRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class TripAssignmentService
{
    private readonly TripRepository $trips;

    public function __construct(TripRepository $trips)
    {
        $this->trips = $trips;
    }
    // ----------
    public function assignDriver(Trip $trip, Driver $driver, User $actor, ?int $expectedVersion = null): Trip
    {
        $this->authorizeAction($actor, 'assign');

        return DB::transaction(function () use ($trip, $driver, $actor, $expectedVersion): Trip {
            $lockedTrip = $this->trips->findForUpdateOrFail($trip->getKey());
            $lockedDriver = $this->trips->findDriverForUpdate($driver->getKey());

            if (! $lockedDriver) {
                throw (new ModelNotFoundException())->setModel(Driver::class, [$driver->getKey()]);
            }

            if ($expectedVersion !== null && $lockedTrip->version !== $expectedVersion) {
                throw new StaleVersionException('This trip was updated by someone else. Please refresh and try again.');
            }

            $this->assertReassignmentAllowed($actor, $lockedTrip);
            $this->assertTripIsAssignable($lockedTrip);
            $this->assertDriverIsAvailable($lockedDriver);
            $this->assertDriverIsNotActiveOnAnotherTrip($lockedDriver, $lockedTrip);

            $previousDriverId = $lockedTrip->driver_id;
            $previousStatus = $lockedTrip->status;
            $previousDriver = $previousDriverId ? $this->trips->findDriverForUpdate($previousDriverId) : null;

            $lockedTrip->driver_id = $lockedDriver->id;
            $lockedTrip->status = 'assigned';
            $lockedTrip->version = (int) $lockedTrip->version + 1;
            $this->trips->saveTrip($lockedTrip);

            if ($previousDriver && $previousDriver->id !== $lockedDriver->id) {
                $previousDriver->status = 'available';
                $this->trips->saveDriver($previousDriver);
            }

            $lockedDriver->status = 'assigned';
            $this->trips->saveDriver($lockedDriver);

            $this->trips->createStatusHistory([
                'trip_id' => $lockedTrip->id,
                'previous_status' => $previousStatus,
                'new_status' => 'assigned',
                'changed_by' => $actor->id,
                'metadata' => [
                    'action' => $previousDriverId ? 'trip.reassigned' : 'trip.assigned',
                    'previous_driver_id' => $previousDriverId,
                    'new_driver_id' => $lockedDriver->id,
                    'source' => 'assignment_service',
                ],
                'created_at' => now(),
            ]);

            $this->trips->createActivityLog([
                'actor_id' => $actor->id,
                'action' => $previousDriverId ? 'trip.reassigned' : 'trip.assigned',
                'entity_type' => Trip::class,
                'entity_id' => $lockedTrip->id,
                'previous_values' => [
                    'driver_id' => $previousDriverId,
                    'status' => $previousStatus,
                    'version' => $lockedTrip->version - 1,
                ],
                'new_values' => [
                    'driver_id' => $lockedDriver->id,
                    'status' => 'assigned',
                    'version' => $lockedTrip->version,
                ],
                'reason' => $previousDriverId ? 'Driver reassigned' : 'Driver assigned',
                'created_at' => now(),
            ]);

            return $lockedTrip->fresh();
        });
    }
    // ----------
    private function authorizeAction(User $actor, string $action): void
    {
        if (! $actor->can_dispatch) {
            throw new AuthorizationException('You do not have permission to modify trip assignments.');
        }

        if (! in_array($actor->role, ['dispatcher', 'supervisor'], true)) {
            throw new AuthorizationException('Only dispatchers and supervisors can assign drivers.');
        }

        if ($action === 'assign') {
            return;
        }
    }
    // ----------
    private function assertReassignmentAllowed(User $actor, Trip $trip): void
    {
        if ($trip->driver_id && $actor->role !== 'supervisor') {
            throw new AuthorizationException('Only supervisors can reassign a trip that already has a driver.');
        }
    }
    // ----------
    private function assertTripIsAssignable(Trip $trip): void
    {
        if (! in_array($trip->status, ['pending', 'assigned', 'driver_arriving'], true)) {
            throw new AssignmentException('This trip cannot be assigned in its current state.');
        }
    }
    // ----------
    private function assertDriverIsAvailable(Driver $driver): void
    {
        if ($driver->status !== 'available') {
            throw new AssignmentException('The selected driver is unavailable.');
        }
    }
    // ----------
    private function assertDriverIsNotActiveOnAnotherTrip(Driver $driver, Trip $trip): void
    {
        if ($this->trips->driverHasAnotherActiveTrip($driver, $trip)) {
            throw new AssignmentException('This driver is already assigned to another active trip.');
        }
    }
}

