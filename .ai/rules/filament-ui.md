# Filament and UI Rules

Applies to Filament, Livewire, and administrative UI code.

## Filament Position

Filament is an administration/UI layer, not the entire application architecture.

Use Filament for:

- forms;
- tables;
- filters;
- actions;
- dashboards;
- resource management.

## Simple CRUD

For basic master-data CRUD, straightforward Filament resource logic is acceptable.

Do not create unnecessary Services solely because Filament is being used.

## Critical Actions

When a Filament action affects:

- balances;
- inventory;
- multiple tables;
- accounting;
- audit trails;

delegate the business operation to reusable application logic such as a Service or Action.

This enables reuse from:

- Filament;
- Blade;
- Livewire;
- REST API;
- mobile app;
- jobs.

## UI Behavior

Prefer responsive and fast workflows for field operations.

POS-style interfaces may later support:

- quick customer lookup;
- QR code lookup;
- multi-line waste entry;
- thermal receipt printing;
- fast keyboard navigation.

Critical validation and financial calculations must not exist only in JavaScript or UI components.

## Hardware Integration

Keep hardware integrations decoupled behind clear interfaces or API contracts.

Examples:

- digital weighing scale;
- biometric device;
- receipt printer;
- Python hardware bridge.
