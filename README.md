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

1. Create a MySQL database and configure the `DB_HOST`, `DB_USER`, `DB_PASS`, and `DB_NAME` environment variables. See `.env.example` for the variable names. The application reads them from the server environment; it does not load `.env` files automatically.
2. Install PHP dependencies with `composer install`.
3. Import the required schema from the SQL files in `database/` and configure the web server document root to this project.
4. Sign in using an account provisioned in the database, then configure the site name and logo in Site Settings.

## Reports and expenses

Reports use the configured site name and logo in printable report output and PDF exports. Expenses can be recorded and reviewed from the Expenses section; they are included in the financial summary and expenditure report.

## Security

Do not commit database credentials, `.env` files, logs, or user-uploaded files. Set database credentials through the hosting environment before deploying.
