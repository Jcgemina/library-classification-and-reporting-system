# Security Review

Reviewed: 2026-11-21
Scope: Whole system, including staged and unstaged changes

## Findings

| # | Severity | File | Lines | Vulnerability | Confidence |
|---|----------|------|-------|---------------|------------|
| 1 | HIGH | `create_admin.php`; `includes/user_service.php` | 47-61; 25-43 | Predictable hardcoded default credentials and automatic password reseeding | 10/10 |

## 1. Predictable credentials and password reseeding

### Evidence

- `create_admin.php:47-49` provisions `admin`, `librarian1`, `librarian2`, and `librarian3` with the password `123`.
- `create_admin.php:52-60` uses `ON DUPLICATE KEY UPDATE` to reset existing accounts' passwords to `123`, even if those accounts previously changed their credentials.
- `includes/user_service.php:25-43` seeds the same accounts with password `123` whenever no admin or librarian account exists.
- `auth.php:59-101` verifies the password and grants role-based sessions, with no forced initial-password change.

### Impact

An attacker who knows the source can authenticate as an administrator or librarian, change library data, or access role-restricted functions.

### Remediation

- Remove hardcoded passwords and all default-account reseeding.
- Require a secure bootstrap secret or an interactive first-run setup that generates unique credentials.
- Force password rotation after the first login.
- Change duplicate-account handling so it never overwrites an existing password or role.
