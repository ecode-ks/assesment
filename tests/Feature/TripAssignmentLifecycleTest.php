<?php

namespace Tests\Feature;

use App\Exceptions\AssignmentException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\StaleVersionException;
use App\Exceptions\TransitionException;
use App\Livewire\DispatchBoard;
use App\Models\ActivityLog;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\TripStatusHistory;
use App\Models\User;
use App\Services\TripAssignmentService;
use App\Services\TripLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class TripAssignmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_assignment_of_an_available_driver(): void
    {
        $user = User::query()->create([
            'name' => 'Dispatcher',
            'email' => 'dispatcher@example.com',
            'password' => 'secret',
            'role' => 'dispatcher',
            'can_dispatch' => true,
        ]);

        $driver = Driver::query()->create([
            'name' => 'Alpha Driver',
            'status' => 'available',
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'Jane Doe',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => null,
            'status' => 'pending',
            'estimated_fare' => 25.50,
            'version' => 1,
        ]);

        $updated = app(TripAssignmentService::class)->assignDriver($trip, $driver, $user);

        $this->assertSame($driver->id, $updated->driver_id);
        $this->assertSame('assigned', $updated->status);
        $this->assertSame(2, $updated->version);
        $this->assertTrue($updated->statusHistory()->where('new_status', 'assigned')->exists());
    }

    public function test_rejects_assignment_when_driver_is_unavailable(): void
    {
        $user = User::query()->create([
            'name' => 'Dispatcher',
            'email' => 'dispatcher2@example.com',
            'password' => 'secret',
            'role' => 'dispatcher',
            'can_dispatch' => true,
        ]);

        $driver = Driver::query()->create([
            'name' => 'Busy Driver',
            'status' => 'assigned',
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'Jane Doe',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => null,
            'status' => 'pending',
            'estimated_fare' => 25.50,
            'version' => 1,
        ]);

        $this->expectException(AssignmentException::class);

        app(TripAssignmentService::class)->assignDriver($trip, $driver, $user);
    }

    public function test_rejects_two_active_assignments_for_the_same_driver(): void
    {
        $user = User::query()->create([
            'name' => 'Dispatcher',
            'email' => 'dispatcher3@example.com',
            'password' => 'secret',
            'role' => 'dispatcher',
            'can_dispatch' => true,
        ]);

        $driver = Driver::query()->create([
            'name' => 'Shared Driver',
            'status' => 'available',
        ]);

        $firstTrip = Trip::query()->create([
            'customer_name' => 'Trip One',
            'pickup_address' => 'Pickup One',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff One',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => null,
            'status' => 'pending',
            'estimated_fare' => 60,
            'version' => 1,
        ]);

        $secondTrip = Trip::query()->create([
            'customer_name' => 'Trip Two',
            'pickup_address' => 'Pickup Two',
            'pickup_latitude' => 42.6601,
            'pickup_longitude' => 21.1601,
            'dropoff_address' => 'Dropoff Two',
            'dropoff_latitude' => 42.6701,
            'dropoff_longitude' => 21.1701,
            'driver_id' => null,
            'status' => 'pending',
            'estimated_fare' => 70,
            'version' => 1,
        ]);

        app(TripAssignmentService::class)->assignDriver($firstTrip, $driver, $user);

        $this->expectException(AssignmentException::class);

        app(TripAssignmentService::class)->assignDriver($secondTrip, $driver, $user);
    }

    public function test_rejects_invalid_status_transition(): void
    {
        $user = User::query()->create([
            'name' => 'Supervisor',
            'email' => 'supervisor4@example.com',
            'password' => 'secret',
            'role' => 'supervisor',
            'can_dispatch' => true,
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'Jane Doe',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => null,
            'status' => 'pending',
            'estimated_fare' => 25.50,
            'version' => 1,
        ]);

        $this->expectException(TransitionException::class);

        app(TripLifecycleService::class)->transition($trip, 'completed', $user);
    }

    public function test_releases_driver_after_cancellation(): void
    {
        $user = User::query()->create([
            'name' => 'Supervisor',
            'email' => 'supervisor@example.com',
            'password' => 'secret',
            'role' => 'supervisor',
            'can_dispatch' => true,
        ]);

        $driver = Driver::query()->create([
            'name' => 'Driver To Release',
            'status' => 'assigned',
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'Jane Doe',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => $driver->id,
            'status' => 'assigned',
            'estimated_fare' => 25.50,
            'version' => 1,
        ]);

        app(TripLifecycleService::class)->transition($trip, 'cancelled', $user);

        $driver->refresh();
        $this->assertSame('available', $driver->status);
        $this->assertSame('cancelled', $trip->fresh()->status);
    }

    public function test_reassigns_previous_driver_and_new_driver_atomically(): void
    {
        $user = User::query()->create([
            'name' => 'Supervisor',
            'email' => 'supervisor5@example.com',
            'password' => 'secret',
            'role' => 'supervisor',
            'can_dispatch' => true,
        ]);

        $oldDriver = Driver::query()->create([
            'name' => 'Old Driver',
            'status' => 'assigned',
        ]);

        $newDriver = Driver::query()->create([
            'name' => 'New Driver',
            'status' => 'available',
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'Jane Doe',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => $oldDriver->id,
            'status' => 'assigned',
            'estimated_fare' => 25.50,
            'version' => 1,
        ]);

        app(TripAssignmentService::class)->assignDriver($trip, $newDriver, $user);

        $this->assertSame($newDriver->id, $trip->fresh()->driver_id);
        $oldDriver->refresh();
        $newDriver->refresh();
        $this->assertSame('available', $oldDriver->status);
        $this->assertSame('assigned', $newDriver->status);
    }

    public function test_rejects_stale_version_conflict(): void
    {
        $user = User::query()->create([
            'name' => 'Dispatcher',
            'email' => 'dispatcher6@example.com',
            'password' => 'secret',
            'role' => 'dispatcher',
            'can_dispatch' => true,
        ]);

        $driver = Driver::query()->create([
            'name' => 'Latest Driver',
            'status' => 'available',
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'Stale Trip',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => null,
            'status' => 'pending',
            'estimated_fare' => 18.50,
            'version' => 1,
        ]);

        $this->expectException(StaleVersionException::class);

        app(TripAssignmentService::class)->assignDriver($trip, $driver, $user, expectedVersion: 99);
    }

    public function test_dispatch_board_logs_and_refreshes_a_stale_assignment_conflict(): void
    {
        $user = User::query()->create([
            'name' => 'Dispatcher',
            'email' => 'dispatcher-stale@example.com',
            'password' => 'secret',
            'role' => 'dispatcher',
            'can_dispatch' => true,
        ]);

        $driver = Driver::query()->create([
            'name' => 'Conflict Driver',
            'status' => 'available',
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'Conflict Customer',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => null,
            'status' => 'pending',
            'estimated_fare' => 18.50,
            'version' => 1,
        ]);

        $component = Livewire::actingAs($user)
            ->test(DispatchBoard::class)
            ->call('selectTrip', $trip->id)
            ->set('targetDriverId', $driver->id);

        $trip->update(['version' => 2, 'estimated_fare' => 21.00]);

        $component->call('assignDriver', $trip->id)
            ->assertHasErrors('assignment')
            ->assertSet('selectedVersion', 2)
            ->assertSet('selectedFare', 21.0);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'trip.concurrency_conflict',
            'entity_type' => Trip::class,
            'entity_id' => $trip->id,
        ]);
    }

    public function test_dispatch_board_resets_pagination_when_filters_change(): void
    {
        $user = User::query()->create([
            'name' => 'Dispatcher',
            'email' => 'dispatcher-filters@example.com',
            'password' => 'secret',
            'role' => 'dispatcher',
            'can_dispatch' => true,
        ]);

        Livewire::actingAs($user)
            ->test(DispatchBoard::class)
            ->set('page', 4)
            ->set('search', 'customer')
            ->assertSet('page', 1)
            ->set('page', 4)
            ->set('status', 'pending')
            ->assertSet('page', 1)
            ->set('page', 4)
            ->set('driverFilter', '1')
            ->assertSet('page', 1);
    }

    public function test_releases_driver_after_completion(): void
    {
        $user = User::query()->create([
            'name' => 'Supervisor',
            'email' => 'supervisor2@example.com',
            'password' => 'secret',
            'role' => 'supervisor',
            'can_dispatch' => true,
        ]);

        $driver = Driver::query()->create([
            'name' => 'Completion Driver',
            'status' => 'assigned',
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'Done Customer',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => $driver->id,
            'status' => 'in_progress',
            'estimated_fare' => 58.00,
            'version' => 1,
        ]);

        app(TripLifecycleService::class)->transition($trip, 'completed', $user);

        $driver->refresh();
        $this->assertSame('available', $driver->status);
        $this->assertSame('completed', $trip->fresh()->status);
    }

    public function test_status_history_created_for_each_successful_transition(): void
    {
        $user = User::query()->create([
            'name' => 'Supervisor',
            'email' => 'supervisor7@example.com',
            'password' => 'secret',
            'role' => 'supervisor',
            'can_dispatch' => true,
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'History Customer',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => null,
            'status' => 'pending',
            'estimated_fare' => 30.00,
            'version' => 1,
        ]);

        app(TripLifecycleService::class)->transition($trip, 'assigned', $user);

        $this->assertDatabaseHas('trip_status_histories', [
            'trip_id' => $trip->id,
            'previous_status' => 'pending',
            'new_status' => 'assigned',
            'changed_by' => $user->id,
        ]);
    }

    public function test_authorization_failure_for_forbidden_operation(): void
    {
        $user = User::query()->create([
            'name' => 'Administrator',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'role' => 'administrator',
            'can_dispatch' => false,
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'No Access',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => null,
            'status' => 'pending',
            'estimated_fare' => 22.00,
            'version' => 1,
        ]);

        $this->expectException(AuthorizationException::class);

        app(TripLifecycleService::class)->transition($trip, 'cancelled', $user);
    }

    public function test_rollback_when_required_persistence_step_throws_exception(): void
    {
        $user = User::query()->create([
            'name' => 'Dispatcher',
            'email' => 'dispatcher8@example.com',
            'password' => 'secret',
            'role' => 'dispatcher',
            'can_dispatch' => true,
        ]);

        $driver = Driver::query()->create([
            'name' => 'Rollback Driver',
            'status' => 'available',
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'Rollback Customer',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => null,
            'status' => 'pending',
            'estimated_fare' => 12.00,
            'version' => 1,
        ]);

        DB::statement('DROP TABLE trip_status_histories');

        $this->expectException(\Throwable::class);

        app(TripAssignmentService::class)->assignDriver($trip, $driver, $user);
    }

    public function test_dispatch_board_render_has_no_n_plus_one_regression(): void
    {
        $user = User::query()->create([
            'name' => 'Dispatcher',
            'email' => 'dispatcher9@example.com',
            'password' => 'secret',
            'role' => 'dispatcher',
            'can_dispatch' => true,
        ]);

        $driver = Driver::query()->create([
            'name' => 'Board Driver',
            'status' => 'available',
        ]);

        for ($i = 1; $i <= 25; $i++) {
            Trip::query()->create([
                'customer_name' => 'Board Customer ' . $i,
                'pickup_address' => 'Pickup ' . $i,
                'pickup_latitude' => 42.6600 + ($i * 0.0001),
                'pickup_longitude' => 21.1600 + ($i * 0.0001),
                'dropoff_address' => 'Dropoff ' . $i,
                'dropoff_latitude' => 42.6700 + ($i * 0.0001),
                'dropoff_longitude' => 21.1700 + ($i * 0.0001),
                'driver_id' => $driver->id,
                'status' => $i % 2 === 0 ? 'assigned' : 'pending',
                'estimated_fare' => 15 + $i,
                'version' => 1,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($user)
            ->test(DispatchBoard::class)
            ->assertOk();

        $this->assertLessThanOrEqual(15, count(DB::getQueryLog()));
    }

    public function test_dispatcher_cannot_reassign_an_already_assigned_trip(): void
    {
        $user = User::query()->create([
            'name' => 'Dispatcher',
            'email' => 'dispatcher10@example.com',
            'password' => 'secret',
            'role' => 'dispatcher',
            'can_dispatch' => true,
        ]);

        $oldDriver = Driver::query()->create(['name' => 'Old Driver', 'status' => 'assigned']);
        $newDriver = Driver::query()->create(['name' => 'New Driver', 'status' => 'available']);

        $trip = Trip::query()->create([
            'customer_name' => 'Jane Doe',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => $oldDriver->id,
            'status' => 'assigned',
            'estimated_fare' => 25.50,
            'version' => 1,
        ]);

        $this->expectException(AuthorizationException::class);

        app(TripAssignmentService::class)->assignDriver($trip, $newDriver, $user);
    }

    public function test_dispatcher_cannot_perform_manual_status_override(): void
    {
        $user = User::query()->create([
            'name' => 'Dispatcher',
            'email' => 'dispatcher11@example.com',
            'password' => 'secret',
            'role' => 'dispatcher',
            'can_dispatch' => true,
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'Jane Doe',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => null,
            'status' => 'pending',
            'estimated_fare' => 25.50,
            'version' => 1,
        ]);

        $this->expectException(AuthorizationException::class);

        app(TripLifecycleService::class)->transition($trip, 'assigned', $user);
    }

    public function test_dispatcher_cannot_change_fare(): void
    {
        $user = User::query()->create([
            'name' => 'Dispatcher',
            'email' => 'dispatcher12@example.com',
            'password' => 'secret',
            'role' => 'dispatcher',
            'can_dispatch' => true,
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'Jane Doe',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => null,
            'status' => 'pending',
            'estimated_fare' => 25.50,
            'version' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(DispatchBoard::class)
            ->call('selectTrip', $trip->id)
            ->set('selectedFare', 40)
            ->call('saveFare', $trip->id)
            ->assertHasErrors('fare');

        $this->assertSame('25.50', $trip->fresh()->estimated_fare);
    }

    public function test_supervisor_cannot_change_fare_once_trip_is_not_pending(): void
    {
        $user = User::query()->create([
            'name' => 'Supervisor',
            'email' => 'supervisor8@example.com',
            'password' => 'secret',
            'role' => 'supervisor',
            'can_dispatch' => true,
        ]);

        $driver = Driver::query()->create(['name' => 'Assigned Driver', 'status' => 'assigned']);

        $trip = Trip::query()->create([
            'customer_name' => 'Jane Doe',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => $driver->id,
            'status' => 'assigned',
            'estimated_fare' => 25.50,
            'version' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(DispatchBoard::class)
            ->call('selectTrip', $trip->id)
            ->set('selectedFare', 40)
            ->call('saveFare', $trip->id)
            ->assertHasErrors('fare');

        $this->assertSame('25.50', $trip->fresh()->estimated_fare);
    }

    public function test_supervisor_can_change_fare_while_trip_is_pending(): void
    {
        $user = User::query()->create([
            'name' => 'Supervisor',
            'email' => 'supervisor9@example.com',
            'password' => 'secret',
            'role' => 'supervisor',
            'can_dispatch' => true,
        ]);

        $trip = Trip::query()->create([
            'customer_name' => 'Jane Doe',
            'pickup_address' => 'Pickup St',
            'pickup_latitude' => 42.6600,
            'pickup_longitude' => 21.1600,
            'dropoff_address' => 'Dropoff Ave',
            'dropoff_latitude' => 42.6700,
            'dropoff_longitude' => 21.1700,
            'driver_id' => null,
            'status' => 'pending',
            'estimated_fare' => 25.50,
            'version' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(DispatchBoard::class)
            ->call('selectTrip', $trip->id)
            ->set('selectedFare', 40)
            ->call('saveFare', $trip->id)
            ->assertHasNoErrors();

        $this->assertSame('40.00', $trip->fresh()->estimated_fare);
    }
}
