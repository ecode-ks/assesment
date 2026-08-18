# Technical Decisions

## 1. Livewire, Service, and Repository Boundaries

The application uses a `Livewire -> Service -> Repository` structure. The Livewire component manages user input, component state, and presentation errors. Services contain authorization, transactions, and business rules. `TripRepository` owns Eloquent queries and persistence for trips, drivers, status history, activity logs, and dashboard reads.

This keeps business rules independent of the UI and makes persistence concerns easier to replace or test in isolation.

**Alternative considered:** placing Eloquent queries and business rules directly in the Livewire component. This is faster initially, but it couples UI requests to persistence details and makes rule reuse difficult.

## 2. Explicit Trip Lifecycle and Assignment Services

`TripAssignmentService` is the authoritative path for assignment and reassignment. `TripLifecycleService` controls valid status transitions, and `TripFareService` controls fare updates. This prevents different callers from applying different rules to the same domain operation.

**Alternative considered:** allowing controllers or Livewire methods to update `Trip` models directly. That would duplicate authorization, validation, history, audit, and locking behavior across callers.

## 3. Optimistic Concurrency with Database Transactions

Each trip has a `version` field. Before a write, the service locks the trip row, compares the submitted version with the stored version, and rejects stale requests. Assignment and lifecycle changes execute inside database transactions; affected driver rows are also locked where necessary.

This prevents an older browser tab or concurrent request from silently overwriting a newer change.

**Alternative considered:** frontend-only disabled controls or last-write-wins persistence. Browser state cannot protect data integrity when requests arrive concurrently or from stale sessions.

## 4. Driver Availability and Active-Trip Integrity

The assignment flow verifies that the selected driver is marked as available and does not already belong to another active trip. Reassignment releases the previous driver only after the new assignment is ready, and cancellation or completion releases the assigned driver within the same transaction.

**Alternative considered:** relying only on the driver's displayed status. A status-only check can become inconsistent when a second active trip exists or competing requests are processed at the same time.

## 5. Database-Side Dispatch Board Queries

The dispatch board uses selected columns, eager loading for drivers, database pagination, and a single aggregate query for visible status counts. This keeps query volume stable as the trip list grows and avoids fetching the complete dataset into memory.

**Alternative considered:** retrieving every trip with `get()` and calculating counts in PHP or separate queries. That approach works for small datasets but does not scale and increases the risk of N+1 relationship queries.