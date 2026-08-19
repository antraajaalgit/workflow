# Nagare 流れ — Laravel + MySQL

Nagare is an agency operations system for Antrajaal. This version keeps the original interface and behavior while moving all business data from browser storage into normalized MySQL tables through a Laravel backend.

## Requirements

- PHP 8.3 with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, and `curl`
- Composer 2
- MySQL/MariaDB (the MySQL service included with XAMPP is fine)

## XAMPP setup on Windows

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open `http://localhost/phpmyadmin`, select **New**, and create a database named `nagare` with `utf8mb4_unicode_ci` collation.
3. Open PowerShell in this folder. If PHP is not on `PATH`, use its full path. For a standard XAMPP installation it is `C:\xampp\php\php.exe`.
4. Install and configure the project:

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Then visit `http://127.0.0.1:8000`.

If `php` or `composer` is not recognized, add the PHP directory (for example `C:\xampp\php`) and Composer directory (`C:\ProgramData\ComposerSetup\bin`) to Windows `PATH`, then open a new terminal. You can also run Composer directly:

```powershell
& 'C:\xampp\php\php.exe' 'C:\ProgramData\ComposerSetup\bin\composer.phar' install
```

## Database configuration

The included `.env.example` uses XAMPP defaults:

```dotenv
DB_DATABASE=nagare
DB_USERNAME=root
DB_PASSWORD=
```

Update these values in `.env` if your MySQL credentials differ.

## Data model

Migrations create dedicated tables for clients, users, tasks, messages/voice notes, activity, outbound notifications, delegation rules, and settings. Demo data is installed by the database seeder. **Settings → Reset demo data** resets these tables.

The login remains intentionally passwordless to preserve prototype behavior, but the selected user is now held in Laravel's server-side session—not localStorage. WhatsApp and email messages remain simulated, as in the original prototype.

## Apache virtual host (optional)

For XAMPP Apache, point the document root at this project's `public` folder. Never expose the project root itself. Laravel's built-in server above is the simplest development option.
