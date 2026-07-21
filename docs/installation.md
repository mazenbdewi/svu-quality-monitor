# Installation Guide

## Requirements

SVU Quality Monitor is a Laravel 12 and Filament 5 application.

Required runtime and tools:

- PHP `^8.2`
- Composer
- Node.js and NPM
- A database supported by Laravel, such as MySQL, MariaDB, PostgreSQL, or SQLite

Main Composer packages used by the application:

- `laravel/framework:^12.0`
- `filament/filament:^5.0`
- `maatwebsite/excel:^3.1`
- `barryvdh/laravel-dompdf:^3.1`

## Installation Steps

Clone or copy the project, then install PHP dependencies:

```bash
composer install
```

Create the environment file:

```bash
cp .env.example .env
```

Generate the application key:

```bash
php artisan key:generate
```

Configure database settings in `.env`. Use placeholder-safe values in documentation and never commit real credentials.

Run migrations:

```bash
php artisan migrate
```

Create a Filament user:

```bash
php artisan make:filament-user
```

Install frontend dependencies:

```bash
npm install
```

Build frontend assets:

```bash
npm run build
```

Run the local development server:

```bash
php artisan serve
```

Then open:

```text
http://127.0.0.1:8000/admin
```
