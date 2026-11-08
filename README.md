# Pentecostal Church Management System

A PHP and MySQL application for managing church administration, finances, members, and assets.

## Features

- Member records and church administration
- Income, contributions, expenses, and financial reports
- Asset management for recording and tracking church property
- Church services, programs, announcements, and related records

## Requirements

- PHP 7.4 or newer
- MySQL or MariaDB
- Composer

## Setup

1. Create a MySQL database, copy `.env.example` to `.env` in the site root, and fill in `APP_URL`, `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS`. The application loads `.env` through `config/env.php`; real server environment variables override it. `.htaccess` blocks `.env` from the web.
2. Install PHP dependencies with `composer install`.
3. Import the required schema from the SQL files in `database/` and configure the web server document root to this project.
4. Sign in using an account provisioned in the database, then configure the site name and logo in Site Settings.

## PWA branding

The installable app uses the church logo and name from Site Settings, not the bundled PWA icons. Apache must allow the project's `.htaccess` rewrite rules so `manifest.webmanifest` serves the current settings.

Enable PHP GD (with PNG/JPEG/WebP support) to generate square 192px and 512px install icons and a 180px Apple touch icon from uploaded logos. Without GD, or with an external logo URL, the original image is used; use a square image at least 512px wide for browser compatibility. No stock icon is supplied when no logo is configured.

Existing installations may take time to refresh their icon. On devices that retain the old icon, remove the installed app and install it again after refreshing the site. Run `php tools/test-pwa.php` to check the branding helpers without a database.

## Reports and expenses

Reports use the configured site name and logo in printable report output and PDF exports. Expenses can be recorded and reviewed from the Expenses section; they are included in the financial summary and expenditure report.

## Security

Do not commit database credentials, `.env` files, logs, or user-uploaded files. Keep database credentials in the server's `.env` file (or the hosting environment).
