# Testing

The suite is intentionally small: one Craft-backed integration suite with 11 tests protecting the plugin's core invariants.

## Setup

1. Copy `tests/.env.example` to `tests/.env`.

2. Create a disposable test database and configure `tests/.env` to use it. The database name must contain `test` as a separate word.

   For the standard DDEV MySQL/MariaDB setup:

   ```bash
   ddev mysql -e "CREATE DATABASE IF NOT EXISTS unique_value_field_test; GRANT ALL ON unique_value_field_test.* TO 'db'@'%'; FLUSH PRIVILEGES;"
   ```

3. Install dependencies:

   ```bash
   ddev composer install
   ```

4. Run the test suite:

   ```bash
   ddev composer test
   ```

The Craft test module drops and rebuilds the configured database. `tests/_craft/config/db.php` refuses database names that do not clearly look like test databases.

## Scope

The suite covers duplicate rejection and self-edits, drafts/canonicals, case-insensitive matching, entry-type, entry-section, custom-field and multisite scoping, auto-suffixing, representative format validation, normalisation, and multiple empty values.

It deliberately does not try to exhaustively test every format preset or every Craft field/layout combination.
