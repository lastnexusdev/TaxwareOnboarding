# Taxware Onboarding v2

This folder contains a full, isolated recreation of the existing onboarding application.

## Scope

- All existing PHP endpoints/pages from the root version were copied into `v2/`.
- Shared include assets were copied into `v2/includes/`.
- Styling was copied to `v2/styles.css`.

## Compatibility and optimization updates

To preserve current behavior while reducing common failure points, `v2` introduces:

- A hardened `db.php` connection bootstrap with utf8mb4 charset and optional env-based DB configuration.
- A stricter and safer `auth_check.php` role gate with explicit 403 responses.
- Cleaner session redirect logic in `index.php`.
- More complete session cleanup in `logout.php`.
- Defensive session value handling in `includes/header.php`.

## Run

Point your web server document root to the repository and access `/v2/login.php`.
