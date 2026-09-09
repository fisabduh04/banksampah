# Inventory Rules

Applies to stock, waste intake, waste sales, adjustments, and inventory reporting.

## Stock Auditability

Do not rely only on a mutable `stock_kg` field for long-term auditability.

The target design should maintain inventory movements.

Suggested structure:

```text
inventory_movements

id
waste_type_id
movement_type
reference_type
reference_id
quantity_in
quantity_out
balance_after
created_by
created_at
```

A current stock column may be maintained for performance if it remains reconcilable with the movement ledger.

## Inventory Writes

Operations that change inventory and another business state must be atomic.

Examples:

- posting a deposit;
- selling waste;
- stock adjustment;
- reversal.

Use `DB::transaction()` when multiple related writes are involved.

## Corrections

Posted inventory-affecting transactions should not be silently edited.

Use adjustment or reversal records when appropriate.

## Stock Concurrency

Use transaction locking or atomic database operations when concurrent stock updates may occur.

Avoid negative stock unless the business explicitly allows it and the behavior is documented.
