# Transaction Evidence and Attachments

Applies to uploads, transaction evidence, receipts, and supporting documents.

The system should support auditable evidence attachments where operationally required.

Examples:

- waste weighing photos;
- weighing slips;
- withdrawal transfer receipts;
- payment evidence;
- signed documents;
- supporting transaction documents.

## Storage Design

Do not store binary files directly in transaction tables.

Prefer Laravel Storage and store file metadata/reference in the database.

A scalable structure may use:

```text
transaction_attachments

id
attachable_type
attachable_id
file_type
original_name
storage_disk
storage_path
mime_type
file_size
uploaded_by
created_at
```

Use a polymorphic relationship when attachments may belong to multiple transaction types.

Example:

```text
Deposit
    └── attachments

Withdrawal
    └── attachments

WasteSale
    └── attachments
```

## Security

Files must:

- use generated safe filenames;
- validate MIME type and size;
- never trust client-supplied extensions;
- use private/non-public storage for sensitive financial evidence;
- only be downloadable by authorized users;
- never expose storage paths as authorization;
- preserve historical evidence.

Do not permanently delete evidence attached to posted financial transactions without an explicit retention/audit policy.
