# Backup & Restore Setup Guide

This guide covers the Admin **Backup & Restore** feature for `appsys_library`, including Laragon and standalone MySQL installations.

The app creates a ZIP containing a SQL database dump and the `uploads/` directory. Archives are stored outside the web document root. The feature is available to Admin users only.

The app defaults to a private directory derived from the installation path. Set `BACKUP_DIR` in `.env` to choose another absolute path; it must be writable by PHP and outside all web document roots. The app rejects a backup directory inside the current document root. `backups/.htaccess` is an additional Apache protection only when the active server honors per-directory rules; Nginx does not read `.htaccess`.

For example, on Laragon:

```env
BACKUP_DIR=C:\laragon\library-classification-and-reporting-system-backups
```

When upgrading an existing installation, move existing archive files from the old project `backups/` directory into the configured private directory. Preserve filenames so existing backup records and pending restore references continue to work.

Prospectus PDFs are stored outside the web root in a separate private directory. Set `PROSPECTUS_UPLOAD_DIR` to choose another absolute path; it must be writable by PHP and outside all web document roots. Existing files in `uploads/prospectuses/` must be moved to that directory, preserving their generated filenames. The app serves them only through an authenticated endpoint, and backup creation/restoration includes the private prospectus files. The included `.htaccess` can deny direct Apache requests to legacy files only when the server honors per-directory rules; Nginx does not read it. Remove legacy files from the web root as part of migration or configure an explicit deny rule in the active web-server configuration.

By default, Laragon stores prospectuses in `C:\laragon\<project-folder>-prospectuses`.

Example on Laragon:

```env
PROSPECTUS_UPLOAD_DIR=C:\laragon\library-classification-and-reporting-system-prospectuses
```

## 1. Configure the database connection

Set the application's database settings in its local `.env` file. Use the host and port of the MySQL server that contains the database:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=appsys_library
DB_USER=your_application_user
DB_PASS=your_application_password
```

Laragon's MySQL uses port `3306` in the current team setup. If your selected
MySQL server uses a different port, set `DB_PORT` to that value.

The app's `DB_HOST`, `DB_PORT`, and `DB_NAME` settings must point to the same database instance where you create the backup account and grants. Do not copy database credentials into this guide or commit `.env`.

## 2. Create a dedicated backup account

Connect to Laragon's MySQL server as an administrator. For the current MySQL
installation, the command is:

```text
C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -u root -p -h 127.0.0.1 -P 3306
```

If Laragon has another MySQL version selected, replace the version folder.
Enter the administrator password only at the prompt.

For an app connecting locally with `DB_HOST=127.0.0.1`, create a dedicated account for that host and grant it database-level backup permissions:

```sql
CREATE USER 'appsys_backup'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-strong-password';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT
ON `appsys_library`.*
TO 'appsys_backup'@'127.0.0.1';
```

If that account already exists, use `ALTER USER` to set its password, then apply the `GRANT` statement. The MySQL account host must match how the PHP app connects. For a local TCP connection use `127.0.0.1`; for a connection configured as `localhost`, use the matching MySQL account host. For Docker or a remote database server, use an appropriately restricted host or network instead of granting access from `%`.

The dump includes stored routines. On MySQL 8.0.20 or newer, if the database has routines, also grant:

```sql
GRANT SHOW_ROUTINE ON *.*
TO 'appsys_backup'@'127.0.0.1';
```

Routine metadata permissions differ between MySQL and MariaDB versions. If the dump reports a routine permission error, check the permissions required by your server version.

## 3. Add the backup credentials to `.env`

Use the same account name and password created above:

```env
BACKUP_DB_USER=appsys_backup
BACKUP_DB_PASS=replace-with-a-strong-password
```

If these values are blank, the app falls back to `DB_USER` and `DB_PASS`. A dedicated account is preferable so the application's normal database account does not need extra backup privileges.

The backup and restore actions currently use the same `BACKUP_DB_USER` and `BACKUP_DB_PASS`. The grants above are for creating backups. Restoring replaces database objects and data, so it requires additional write privileges. Grant those only if you intend to use restore, and test restore on a disposable database first.

## 4. Make the MySQL client tools available to PHP

The PHP process needs to run `mysqldump` to create a backup and `mysql` to restore one.

### Laragon

The app searches Laragon's standard `bin\mysql\<version>\bin` location on
Windows. Use the MySQL version selected in Laragon and make sure its client
tools are installed.

### Other standalone MySQL installations on Windows

Add the MySQL `bin` directory to the `PATH` available to the PHP web-server
process, or define these Windows environment variables:

```text
MYSQLDUMP_PATH=C:\path\to\mysql\bin\mysqldump.exe
MYSQL_PATH=C:\path\to\mysql\bin\mysql.exe
```

You may set these values in `.env` or in the PHP process environment. A process
environment value takes precedence. Restart the web server or PHP service after
changing its environment. A path available in an interactive terminal may not
be available to a Windows service.

## 5. Enable PHP ZIP and check filesystem access

- Enable the PHP `zip` extension in the `php.ini` used by the web app, then restart the web server or PHP service.
- Ensure the PHP process can create and write files in the configured backup directory.
- Keep that directory protected from direct public web access. Backup archives contain the database and uploaded files.
- After deployment, request a backup file's direct URL while signed out and confirm the web server denies access; the authenticated backup download endpoint should continue to work.

## 6. Verify backup creation

1. Sign in as an Admin and open **Settings → Backup & Restore**.
2. Select **Create backup**.
3. Confirm that the success message appears and that a new ZIP is listed in **Backup history**.
4. Confirm the archive is in the configured backup directory and is non-empty.

Do not treat a small test ZIP or an error message as a successful backup. A valid archive contains `database.sql` and an `uploads/` directory.

## Troubleshooting

### “The backup account needs permission to read all database tables and views”

Check that the account can log in to the same MySQL host and port as the app and that the grants were applied to `appsys_library` on that server. A MySQL `1045` error means authentication failed; check the username, password, and account host before changing table privileges.

### Restore reports MySQL error 1227 about `SYSTEM_USER`

Backups taken by a system account (such as `root`) contain `DEFINER=` clauses. The app removes every DEFINER clause from the dump when creating a backup and again before importing one, so an archive is never the reason for this error.

The cause is in the target database: its views, functions, procedures, events or triggers were created by another account (usually `root`), and MySQL does not let the restore account drop or replace them.

The app handles this automatically. Before importing, it reads the objects the archive recreates. If the backup account (`BACKUP_DB_USER`) can drop the ones owned by another account, it does so, and the import recreates them under `RESTORE_DB_USER` so later restores replace them normally.

If the backup account cannot drop them — the usual case when it is not a MySQL administrator — the restore still completes. The import continues past those objects, they keep their current definition, and everything else (tables, data and uploaded files) is restored. The success message names the objects that were left unchanged, so apply newer definitions by hand if the archive contains them. Do not grant `SYSTEM_USER` or `SET_ANY_DEFINER` to the application or restore account.

### MySQL client not found

Check that `mysqldump.exe` exists and that PHP can access it through `PATH` or `MYSQLDUMP_PATH`. Restore also requires `mysql.exe`, available through `PATH` or `MYSQL_PATH`.

### ZIP extension unavailable

Enable `zip` in the `php.ini` used by the web server, not only in a different command-line PHP installation.

### Backup directory cannot be written

Grant the PHP/web-server process write access to the configured backup directory. Do not make the entire project or backup directory publicly writable.

## Restore safety

Restoring replaces the current database contents and uploaded files with those from the selected archive. Verify the archive and use a staging or disposable database before restoring to a live system. Keep a separate recovery copy before any production restore.
