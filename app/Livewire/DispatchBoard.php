<?php

namespace App\Livewire;

use App\Exceptions\AssignmentException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\StaleVersionException;
use App\Exceptions\TransitionException;
use App\Services\DispatchBoardService;
use App\Services\TripAssignmentService;
use App\Services\TripFareService;
use App\Services\TripLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class DispatchBoard extends Component
{
    public string $search = '';
    public string $status = '';
    public string $driverFilter = '';
    public int $page = 1;
    public int $perPage = 15;

    public ?int $selectedTripId = null;
    public ?int $targetDriverId = null;
    public ?float $selectedFare = null;
    public ?int $selectedVersion = null;

    protected DispatchBoardService $dispatchBoard;
    protected TripAssignmentService $assignmentService;
    protected TripFareService $fareService;
    protected TripLifecycleService $lifecycleService;

    public function boot(
        DispatchBoardService $dispatchBoard,
        TripAssignmentService $assignmentService,
        TripFareService $fareService,
        TripLifecycleService $lifecycleService
    ): void {
        $this->dispatchBoard = $dispatchBoard;
        $this->assignmentService = $assignmentService;
        $this->fareService = $fareService;
        $this->lifecycleService = $lifecycleService;
    }
    // ----------
    public function updatedSearch(): void
    {
        $this->resetPage();
    }
    // ----------
    public function updatedStatus(): void
    {
        $this->resetPage();
    }
    // ----------
    public function updatedDriverFilter(): void
    {
        $this->resetPage();
    }
    // ----------
    private function resetPage(): void
    {
        $this->page = 1;
    }
    // ----------
    private function handleStaleConflict(int $tripId, string $operation, ?int $expectedVersion): void
    {
        $currentTrip = $this->dispatchBoard->recordConcurrencyConflict(Auth::id(), $tripId, $operation, $expectedVersion);

        if ($currentTrip) {
            $this->selectedTripId = $currentTrip->id;
            $this->targetDriverId = $currentTrip->driver_id;
            $this->selectedFare = (float) $currentTrip->estimated_fare;
            $this->selectedVersion = $currentTrip->version;
        }
    }
    // ----------
    private function handleUnexpectedError(\Throwable $exception, string $field): void
    {
        Log::error('Dispatch board operation failed.', [
            'exception' => $exception,
            'field' => $field,
            'trip_id' => $this->selectedTripId,
        ]);

        $this->addError($field, 'The operation could not be completed. Please try again.');
    }
    // ----------
    public function selectTrip(int $tripId): void
    {
        $trip = $this->dispatchBoard->findTripOrFail($tripId);

        $this->selectedTripId = $trip->id;
        $this->targetDriverId = $trip->driver_id;
        $this->selectedFare = $trip->estimated_fare;
        $this->selectedVersion = $trip->version;

        $this->resetErrorBag();
    }
    // ----------
    public function assignDriver(int $tripId): void
    {
        $validator = Validator::make(
            [
                'targetDriverId' => $this->targetDriverId,
            ],
            [
                'targetDriverId' => ['required', 'integer', 'exists:drivers,id'],
            ]);

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());
            return;
        }

        $trip = $this->dispatchBoard->findTripOrFail($tripId);
        $driver = $this->dispatchBoard->findDriverOrFail($this->targetDriverId);

        // Makes the race easier to reproduce during the assessment.
        usleep(250000);

        try {
            $trip = $this->assignmentService->assignDriver($trip, $driver, Auth::user(), $this->selectedVersion);
        } catch (StaleVersionException $e) {
            $this->handleStaleConflict($tripId, 'assignment', $this->selectedVersion);
            $this->addError('assignment', $e->getMessage());
            return;
        } catch (AssignmentException|AuthorizationException $e) {
            $this->addError('assignment', $e->getMessage());
            return;
        } catch (\Throwable $e) {
            $this->handleUnexpectedError($e, 'assignment');
            return;
        }

        $this->selectedVersion = $trip->version;
        session()->flash('success', 'Driver assignment updated.');
    }
    // ----------
    public function cancelTrip(int $tripId): void
    {
        $trip = $this->dispatchBoard->findTripOrFail($tripId);

        try {
            $trip = $this->lifecycleService->transition($trip, 'cancelled', Auth::user(), $this->selectedVersion);
        } catch (StaleVersionException $e) {
            $this->handleStaleConflict($tripId, 'cancellation', $this->selectedVersion);
            $this->addError('status', $e->getMessage());
            return;
        } catch (AuthorizationException|TransitionException $e) {
            $this->addError('status', $e->getMessage());
            return;
        } catch (\Throwable $e) {
            $this->handleUnexpectedError($e, 'status');
            return;
        }

        $this->selectedVersion = $trip->version;
        session()->flash('success', 'Trip cancelled.');
    }
    // ----------
    public function changeStatus(int $tripId, string $status): void
    {
        $trip = $this->dispatchBoard->findTripOrFail($tripId);

        try {
            $trip = $this->lifecycleService->transition($trip, $status, Auth::user(), $this->selectedVersion);
        } catch (StaleVersionException $e) {
            $this->handleStaleConflict($tripId, 'status_change', $this->selectedVersion);
            $this->addError('status', $e->getMessage());
            return;
        } catch (AuthorizationException|TransitionException $e) {
            $this->addError('status', $e->getMessage());
            return;
        } catch (\Throwable $e) {
            $this->handleUnexpectedError($e, 'status');
            return;
        }

        $this->selectedVersion = $trip->version;
        session()->flash('success', 'Trip status updated.');
    }
    // ----------
    public function saveFare(int $tripId): void
    {
        $this->validate([
            'selectedFare' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $trip = $this->fareService->updateFare($tripId, $this->selectedFare, Auth::user(), $this->selectedVersion);
        } catch (StaleVersionException $e) {
            $this->handleStaleConflict($tripId, 'fare_update', $this->selectedVersion);
            $this->addError('fare', $e->getMessage());
            return;
        } catch (AuthorizationException|\RuntimeException $e) {
            $this->addError('fare', $e->getMessage());
            return;
        } catch (\Throwable $e) {
            $this->handleUnexpectedError($e, 'fare');
            return;
        }

        $this->selectedVersion = $trip->version;
        session()->flash('success', 'Estimated fare updated.');
    }
    // ----------
    public function refreshSelected(): void
    {
        if (!$this->selectedTripId) {
            return;
        }

        $trip = $this->dispatchBoard->findTripOrFail($this->selectedTripId);
        $this->targetDriverId = $trip->driver_id;
        $this->selectedFare = (float)$trip->estimated_fare;
        $this->selectedVersion = $trip->version;
    }
    // ----------
    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }
    // ----------
    public function render()
    {
        $dashboard = $this->dispatchBoard->dashboardData(
            $this->search,
            $this->status,
            $this->driverFilter,
            $this->perPage,
            $this->page,
        );
        $trips = $dashboard['trips'];

        if ($this->page > $trips->lastPage()) {
            $this->page = max(1, $trips->lastPage());
            $dashboard = $this->dispatchBoard->dashboardData(
                $this->search,
                $this->status,
                $this->driverFilter,
                $this->perPage,
                $this->page,
            );
            $trips = $dashboard['trips'];
        }

        $selectedTrip = $this->selectedTripId
            ? $this->dispatchBoard->findDispatchTrip($this->selectedTripId)
            : null;
        $statusCounts = $dashboard['counts'];

        return view('livewire.dispatch-board', [
            'trips' => $trips,
            'drivers' => $dashboard['drivers'],
            'selectedTrip' => $selectedTrip,
            'selectedHistory' => $selectedTrip ? $selectedTrip->statusHistory : collect(),
            'counts' => [
                'pending' => (int) ($statusCounts->pending ?? 0),
                'assigned' => (int) ($statusCounts->assigned ?? 0),
                'in_progress' => (int) ($statusCounts->in_progress ?? 0),
                'completed' => (int) ($statusCounts->completed ?? 0),
            ],
        ]);
    }
}
