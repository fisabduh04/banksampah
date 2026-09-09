# Testing, Security, and Production Rules

Applies to tests, production-readiness, security, deployment, and performance.

## Testing Priority

Business-critical code should progressively receive automated tests.

Priority:

```text
1. financial calculations
2. deposit posting
3. withdrawals
4. balance mutation
5. inventory movement
6. authorization
7. transaction reversals
```

Follow the existing project test framework and Laravel Boost testing rules.

## Security

Always consider:

- authentication;
- authorization;
- CSRF;
- mass assignment;
- SQL injection;
- XSS;
- validation;
- file upload security;
- sensitive data exposure;
- role escalation;
- direct object access.

Use Laravel's built-in protections.

Never expose or commit secrets from `.env`.

## Error Handling

Do not hide failures.

Use:

- validation errors for invalid input;
- authorization failures for forbidden actions;
- domain exceptions for invalid business operations;
- database rollback for failed transactions;
- logs for unexpected failures.

Technical details should remain in logs, not be shown to end users.

## Performance

Do not optimize prematurely, but avoid obvious issues.

Consider indexes for:

- foreign keys;
- transaction numbers;
- customer codes;
- dates;
- statuses;
- frequent lookup fields.

Use pagination for large datasets.

Avoid N+1 queries.

Measure before major optimization.

## Production Readiness

Before production, review:

- automated tests;
- authorization;
- validation;
- concurrency;
- database indexing;
- backups;
- logs;
- audit trails;
- environment security;
- deployment configuration;
- performance.
