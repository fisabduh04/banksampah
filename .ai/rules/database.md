# Database and Model Rules

Applies to migrations, models, schema changes, and persisted domain data.

## Inspect First

Before creating or changing database structures:

- inspect existing schema;
- inspect related models;
- inspect existing naming conventions;
- preserve existing project patterns unless they are unsafe.

## Naming

Prefer clear Laravel-style names.

Examples:

```text
customers
waste_categories
waste_types
waste_prices
deposits
deposit_details
withdrawals
balance_mutations
inventory_movements
transaction_attachments
```

Avoid unclear abbreviations.

## Foreign Keys

Use actual foreign keys whenever practical.

Example:

```php
$table->foreignId('customer_id')
    ->constrained()
    ->restrictOnDelete();
```

Choose delete behavior deliberately.

Do not cascade-delete financial history casually.

## Money

Never use `float` or `double` for money.

Use fixed precision decimal.

Example:

```php
$table->decimal('amount', 15, 2);
```

Choose precision based on realistic business limits.

## Weight

Use fixed precision decimal.

Example:

```php
$table->decimal('weight_kg', 12, 3);
```

Choose precision based on scale/device requirements.

## Business IDs

Use standard primary keys unless there is a justified alternative.

Business identifiers should be separate.

Example:

```text
id: 1257
customer_code: BS-2026-01257
```

Do not use formatted customer codes as primary keys without a strong reason.

## Customer Code

Customer codes must be unique and concurrency-safe.

Target format:

```text
BS-YYYY-XXXXX
```

Do not use:

```php
Customer::count() + 1
```

for production numbering.

The implementation must prevent duplicate codes under concurrent requests.

## Validation and Constraints

Application validation must be reinforced by database constraints for critical fields.

Examples:

- unique customer code;
- non-null foreign keys;
- appropriate indexes;
- unique transaction numbers where required.

## Soft Deletes

Use `SoftDeletes` selectively on master/reference data when historical relationships must be preserved.

Do not apply `SoftDeletes` automatically to every model.
