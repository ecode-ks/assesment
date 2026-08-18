# ScanTech Dispatch Assessment

A Laravel and Livewire dispatch management application for assigning drivers, managing trip status, updating pending-trip fares, and recording operational audit history.

## Technology Stack

- PHP 8.2 or later
- Laravel 12
- Livewire 3
- Vite and Tailwind CSS
- SQLite for local development

## Prerequisites

Install the following tools before setting up the application:

- PHP 8.2 or later
- Composer
- Node.js and npm

## Setup

Run these commands from the project root in PowerShell:

```powershell
composer install
npm install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File -Path database/database.sqlite -Force
php artisan migrate --seed
npm run build
```

If `.env` already exists, do not run `Copy-Item .env.example .env`, because it would overwrite local configuration.

The default local environment uses SQLite. Confirm that the following value remains in `.env` unless you intentionally configure another database:

```env
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
```

## Migrations and Seed Data

The setup command `php artisan migrate --seed` runs all database migrations, including the dispatch-query indexes, and then executes `DatabaseSeeder`.

The seed data provides:

- Three local roles: dispatcher, supervisor, and administrator.
- Sixty drivers in `available`, `assigned`, and `offline` states.
- A representative trip queue across all supported trip statuses.
- Initial trip status-history records for seeded trips.

To recreate the local database from scratch, use:

```powershell
php artisan migrate:fresh --seed
```

## Start the Application

Open two PowerShell terminals in the project root.

Terminal 1 starts the Laravel application:

```powershell
php artisan serve
```

Terminal 2 starts the Vite development server:

```powershell
npm run dev
```

Open `http://127.0.0.1:8000` in a browser. Local and test environments use the included role-selector sign-in flow.

For a production-style local asset build instead of the Vite development server, run:

```powershell
npm run build
```

## Run Tests

Run the complete automated test suite:

```powershell
php artisan test
```

Run only the dispatch lifecycle feature tests:

```powershell
php artisan test tests/Feature/TripAssignmentLifecycleTest.php
```

## Completed Scope

- Driver assignment and supervisor-only driver reassignment.
- Trip lifecycle transitions with enforced valid status changes.
- Supervisor-only fare updates while a trip is pending.
- Server-side role and dispatch-permission authorization.
- Optimistic concurrency checks using the `trips.version` field.
- Transactional locking for trip and driver updates.
- Activity logs and trip status history for operational changes.
- A paginated Livewire dispatch board with search, status, and driver filters.
- Database-side status aggregates and eager loading to avoid an N+1 query pattern.
- Layered application structure: `Livewire -> Service -> Repository`.

## Assumptions

- A driver can have only one active trip at a time. Active statuses are `assigned`, `driver_arriving`, and `in_progress`.
- Dispatchers can assign available drivers to unassigned trips; only supervisors can reassign a trip, change status, cancel a trip, or change fares.
- A fare can be changed only while the trip status is `pending`.
- Every browser action supplies the last loaded trip version. A request with a stale version is rejected instead of overwriting newer data.
- SQLite is suitable for the assessment's local environment. Production deployment would use a managed database with appropriate operational controls.

## Known Limitations

- Authentication is intentionally an assessment-focused role selector, not a full production authentication and identity-management solution.
- Updates are request-driven; the board does not use WebSockets, broadcasts, or automatic multi-user refresh.
- Pagination uses a manual page property rather than Livewire's built-in pagination trait.
- Audit records capture operational changes but do not provide a separate reporting or export interface.

## Unfinished Items

There are no unfinished items within the assessment scope. The items listed in **Known Limitations** are intentional scope boundaries rather than incomplete implementations.

## Useful Commands

```powershell
php artisan route:list
php artisan migrate:status
php artisan optimize:clear
```

Do not commit `.env`, `vendor/`, local SQLite database files, logs, secrets, or generated build artifacts.