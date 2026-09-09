# Application Architecture Rules

Applies broadly to application code.

## Start with Laravel MVC

For straightforward CRUD, this is acceptable:

```text
Route
→ Controller
→ Model
→ Blade
→ Database
```

Do not force advanced architecture prematurely.

## Controllers

Controllers may initially contain simple CRUD logic.

Move logic out when the controller becomes responsible for:

- multi-table transactions;
- financial calculations;
- stock mutation;
- complex business rules;
- logic reused by Blade, Filament, API, jobs, or Livewire.

Avoid fat controllers.

## Services / Actions

Introduce Services or Actions only when needed.

Example:

```text
Controller / Filament Action
        ↓
DepositService
        ↓
Models / Database
```

Critical business logic should be callable independently from the UI layer.

## Blade

Blade should focus on presentation.

Avoid:

- database queries directly inside Blade;
- important financial calculations in Blade;
- permission logic duplicated across views;
- significant business logic in templates.

Use reusable components for repeated UI where helpful.

## Refactoring

Do not refactor merely for elegance.

Refactor when there is evidence of:

- duplication;
- large controllers;
- difficult testing;
- complex transactions;
- multiple entry points;
- repeated calculations.

Prefer incremental refactoring over rewrites.
