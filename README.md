# AFP Watch Out

A disease surveillance web app for the Ghana Health Service, built with Laravel 13.

## Features

- **AFP 60-day follow-up**: alerts for cases that are past due, due soon or missing a follow-up report; new-case entry; edit or delete within 24 hours; import from file; district and region reports; SMS reminders to district officers.
- **Weekly IDSR**: weekly data entry and import, regional bulletins, and DHIMS2 data fetch.

Each account belongs to one region and sees only that region and its districts. National accounts see every region.

## Requirements

- PHP 8.3 or newer, with the `pdo_sqlite`, `mbstring`, `openssl`, `zip`, `gd`, `xml`, `fileinfo` and `curl` extensions
- Composer 2

Node.js is not needed. The CSS and JavaScript are plain files in `public/static/`.

## Getting started

```sh
composer setup      # installs dependencies, creates .env and the SQLite database, runs migrations
composer dev        # starts the app at http://127.0.0.1:8000
```

Then:

1. **Add the data workbooks.** Put the AFP register workbook and the IDSR workbooks in `storage/app/data/`. They hold patient data, so they are not in the repository. Ask the data manager for copies. The AFP pages show an error until the register is there.
2. **Create the first account.** Open `/login`. While there are no accounts, the sign-in page lets you create the first one.
3. **Optional: create a support (superuser) account** with `php artisan create-support`. It prints a one-time password, and the first sign-in asks for a new one.

## Configuration

All settings are in `.env`; see `.env.example` for the full list. The app-specific ones are:

| Setting | Purpose |
| --- | --- |
| `DATA_DIR`, `PRIVATE_DIR` | Where the workbooks and private files live. They default to `storage/app/data` and `storage/app/private`. |
| `INSTALL_TOKEN` | Turns on the browser setup page at `/install?token=...`, for hosting without a terminal. Leave it empty to keep the page off. |
| `LETSFISH_APP_ID`, `LETSFISH_APP_SECRET`, `LETSFISH_SENDER_ID` | SMS gateway credentials. Leave them empty in development. |
| `SMS_REPEAT_HOURS` | The minimum number of hours before the same case can be texted again. |

## Testing

```sh
composer test
```

The tests use an in-memory database and a fake SMS gateway, so nothing is sent.

## Deployment

Deployment and server steps are kept in the private operations notes, not in this repository.

Never deploy with `APP_DEBUG=true`. Never commit `.env`, the database, the workbooks or the log files; `.gitignore` already excludes them.
