# Bank Sampah Project Rules Index

This directory contains project-specific rules for the Waste Bank Management System.

Read the rules that match the files or feature area you are working on.

## Rule Map

| Rule File | Applies To |
|---|---|
| `project-context.md` | All project files |
| `learning-guidelines.md` | All project files |
| `architecture.md` | `app/**`, `routes/**`, `resources/views/**` |
| `database.md` | `database/**`, `app/Models/**` |
| `financial-integrity.md` | deposits, withdrawals, balances, accounting, payment-related code |
| `services.md` | `app/Services/**`; historical corrections and trial data |
| `inventory.md` | waste stock, waste sales, stock adjustments, inventory reports |
| `filament-ui.md` | `app/Filament/**`, Livewire/Filament UI-related code |
| `attachments.md` | uploads, transaction evidence, receipts, supporting documents |
| `testing-production.md` | `tests/**`, deployment, security, performance, production-readiness |

## Mandatory Reading

Before modifying code:

1. Read `project-context.md`.
2. Read `learning-guidelines.md`.
3. Read every rule file relevant to the path or business feature being changed.
4. Follow the Laravel Boost `AGENTS.md` guidelines and existing project conventions.

Project-specific rules in this directory complement Laravel Boost rules; they do not replace them.
