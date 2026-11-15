# Backup & Restore Setup Guide

This guide covers the Admin **Backup & Restore** feature for `appsys_library`, including Laragon and standalone MySQL installations.

The app creates a ZIP containing a SQL database dump and the `uploads/` directory. Archives are stored in the project's `backups/` directory. The feature is available to Admin users only.

## 1. Configure the database connection

Set the application's database settings in its local `.env` file. Use the host and port of the MySQL server that contains the database:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=appsys_library
DB_USER=your_application_user
DB_PASS=your_application_password
```

`3306` is a common local MySQL port, not a requirement. Use the port configured for your MySQL server. For example, if the server is listening on `3307`, set `DB_PORT=3307`.

The app's `DB_HOST`, `DB_PORT`, and `DB_NAME` settings must point to the same database instance where you create the backup account and grants. Do not copy database credentials into this guide or commit `.env`.

## 2. Create a dedicated backup account

Connect to the intended MySQL server as an administrator. For a local Windows MySQL installation, the command may look like:

```text
C:\Server\MySQL\bin\mysql.exe -u root -p -h 127.0.0.1 -P 3307
```

Change the executable path and port to match your installation. Enter the administrator password only at the prompt.

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

The app searches Laragon's standard `bin\mysql\<version>\bin` location on Windows. Use the MySQL version selected in Laragon and make sure its client tools are installed.

### Standalone MySQL on Windows

Add the MySQL `bin` directory to the `PATH` available to the PHP web-server process, or define these Windows environment variables:

```text
MYSQLDUMP_PATH=C:\Server\MySQL\bin\mysqldump.exe
MYSQL_PATH=C:\Server\MySQL\bin\mysql.exe
```

These two paths are read from the PHP process environment, not from `.env`. Restart the web server or PHP service after changing its environment. A path available in an interactive terminal may not be available to a Windows service.

## 5. Enable PHP ZIP and check filesystem access

- Enable the PHP `zip` extension in the `php.ini` used by the web app, then restart the web server or PHP service.
- Ensure the PHP process can create and write files in the project's `backups/` directory.
- Keep that directory protected from direct public web access. Backup archives contain the database and uploaded files.

## 6. Verify backup creation

1. Sign in as an Admin and open **Settings → Backup & Restore**.
2. Select **Create backup**.
3. Confirm that the success message appears and that a new ZIP is listed in **Backup history**.
4. Confirm the archive is in the project's `backups/` directory and is non-empty.

Do not treat a small test ZIP or an error message as a successful backup. A valid archive contains `database.sql` and an `uploads/` directory.

## Troubleshooting

### “The backup account needs permission to read all database tables and views”

Check that the account can log in to the same MySQL host and port as the app and that the grants were applied to `appsys_library` on that server. A MySQL `1045` error means authentication failed; check the username, password, and account host before changing table privileges.

### MySQL client not found

Check that `mysqldump.exe` exists and that PHP can access it through `PATH` or `MYSQLDUMP_PATH`. Restore also requires `mysql.exe`, available through `PATH` or `MYSQL_PATH`.

### ZIP extension unavailable

Enable `zip` in the `php.ini` used by the web server, not only in a different command-line PHP installation.

### Backup directory cannot be written

Grant the PHP/web-server process write access to the project's `backups/` directory. Do not make the entire project or backup directory publicly writable.

## Restore safety

Restoring replaces the current database contents and uploaded files with those from the selected archive. Verify the archive and use a staging or disposable database before restoring to a live system. Keep a separate recovery copy before any production restore.
