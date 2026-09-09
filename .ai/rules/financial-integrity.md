# Financial Integrity Rules

Applies to deposits, withdrawals, balances, accounting, cash, and payment-related code.

Financial integrity is non-negotiable.

## Atomic Transactions

Any business operation involving multiple financial writes must use a database transaction.

Typical cases:

- posting a deposit;
- approving a withdrawal;
- mutating a customer balance;
- waste sale settlement;
- accounting journal posting.

Example:

```php
DB::transaction(function () {
    // All related writes succeed or fail together.
});
```

## Concurrency

When concurrent requests may modify the same balance or financial state, protect the operation using an appropriate mechanism such as:

- `lockForUpdate()`;
- atomic increment/decrement operations;
- unique constraints;
- transactional sequencing.

Use locking only where concurrency risk is real.

## Balance Ledger

Do not treat a single current-balance field as the only financial record.

The system should maintain a reconcilable balance mutation ledger.

Suggested structure:

```text
balance_mutations

id
customer_id
transaction_type
reference_type
reference_id
debit
credit
balance_before
balance_after
description
performed_by
created_at
```

A cached balance may exist for performance, but it must reconcile with the ledger.

## Posted Transactions

Completed or posted financial transactions must not be silently updated or deleted.

Prefer:

```text
Original Transaction
        ↓
Reversal Transaction
        ↓
Corrected Transaction
```

## Price Snapshot

Deposit details must preserve the actual price used at transaction time.

Suggested fields:

```text
waste_type_id
weight_kg
price_per_kg
subtotal
```

Historical deposits must not change when master prices are updated.

## Accounting

Do not build a full accounting engine before core transactions are stable.

When implemented, accounting should include:

- chart of accounts;
- journal entries;
- journal entry lines;
- balanced debit/credit;
- source references;
- posting date;
- audit trail;
- reversals.

Every journal entry must remain traceable to its source transaction.
