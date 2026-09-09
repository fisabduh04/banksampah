# Developer Learning Guidelines

The developer is currently learning Laravel and understands basic:

- Route
- Controller
- Model
- Migration
- Blade

## Learning by Doing

For every meaningful feature:

1. Inspect the existing implementation first.
2. Explain briefly what will change.
3. Implement the smallest useful step.
4. Explain the important code in simple language.
5. Verify the result.
6. Continue only after the current step is coherent.

Prefer:

```text
Simple Laravel
→ Correct Laravel
→ Tested Laravel
→ Refactor when complexity appears
→ Scale progressively
```

## Do Not Over-Abstraction

Do not introduce the following for trivial CRUD unless there is a real need:

- Service classes;
- repositories;
- DTOs;
- actions;
- events;
- traits;
- value objects.

Introduce abstraction when business complexity justifies it.

Examples of justified reasons:

- writes span multiple tables;
- money or balance changes;
- inventory changes;
- a transaction must be atomic;
- logic is reused from multiple interfaces;
- business rules become difficult to test in a controller.

## Explanation Style

When introducing a new Laravel concept, explain briefly:

```text
Migration = defines database structure.
Model = interacts with a table.
Controller = coordinates requests and responses.
Blade = displays the interface.
Service = holds reusable or complex business logic.
```

Do not repeat explanations the developer already understands unless requested.
