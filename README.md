# TransManagement

Backend API manajemen armada: kendaraan, tipe kendaraan, crew (driver/kondektur), jadwal harian, role & permission, serta gallery media.

Stack: **Laravel 12 API-only**, Sanctum, spatie/laravel-permission, PHPUnit, Pint. SQLite untuk lokal/test, PostgreSQL untuk production.

## Setup Lokal

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Windows (PowerShell):

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item database/database.sqlite -ItemType File -Force
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Seeder membuat role `superadmin`, `admin`, `crew` dan akun superadmin:

- Email: `SUPERADMIN_EMAIL` (default `admin@transmanagement.test`)
- Password: `SUPERADMIN_PASSWORD` (default `password` di lokal)

## Production (PostgreSQL)

Set di `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=transmanagement
DB_USERNAME=...
DB_PASSWORD=...

SUPERADMIN_EMAIL=admin@example.com
SUPERADMIN_PASSWORD=password-yang-kuat
```

`SUPERADMIN_PASSWORD` wajib diisi di production; seeder akan menolak password kosong. Jalankan `php artisan migrate --seed` dan `php artisan storage:link`.

## API

Base URL: `/api/v1`. Kirim header `Authorization: Bearer <token>` untuk endpoint selain login dan file dokumen bertanda tangan. Login dibatasi 10 percobaan/menit.

| Grup | Endpoint |
| :--- | :--- |
| Health | `GET /health` (publik) |
| Auth | `POST /auth/login`, `GET /auth/me`, `POST /auth/logout` |
| Tipe kendaraan | `GET/POST /vehicle-types`, `GET/PUT/DELETE /vehicle-types/{id}` |
| Kendaraan | `GET/POST /vehicles`, `GET/PUT/DELETE /vehicles/{id}` |
| Lampiran | `POST /vehicles/{id}/attachments`, `POST /crews/{id}/attachments` |
| Crew | `GET/POST /crews`, `GET/PUT/DELETE /crews/{id}` |
| Jadwal | `GET /schedules`, `GET /schedules/daily?date=YYYY-MM-DD`, `POST /schedules`, `GET/PUT/DELETE /schedules/{id}` |
| User | `GET/POST /users`, `GET/PUT/DELETE /users/{id}` |
| Role | `GET/POST /roles`, `GET/PUT/DELETE /roles/{id}`, `GET /permissions` |
| Media | `GET /media`, `PUT /media/{id}`, `POST /media/{id}/replace`, `DELETE /media/{id}` |
| File dokumen | `GET /attachments/{id}/file` (signed URL, 30 menit) |

Response sukses: `{ success, message?, data }`. List memakai `data` + `meta` pagination. Error validasi: `{ success: false, message, errors }`.

## Dokumentasi API

- Swagger UI: `/docs`
- Spesifikasi OpenAPI: `/openapi.json`

Spesifikasi dibuat dengan Scramble (dev dependency) dan di-commit sebagai file statis. Setelah route/validation berubah, regenerate:

```bash
composer openapi
```

Lalu commit `public/openapi.json`. Image production tidak menginstal Scramble (`composer install --no-dev`), tapi `/docs` tetap jalan dari file statis.

## Deploy

Lihat [DEPLOY.md](DEPLOY.md). Ringkas: image `ghcr.io/mitrasovadigitalsolutions/transmanagementbackend:latest`, port host `8090`, health check `GET /api/v1/health`, deploy manual via Docker Compose di VPS. CI GitHub Actions menjalankan test lalu build & push image ke GHCR saat push ke `main`.

## Role

| Role | Akses |
| :--- | :--- |
| superadmin | Semua, termasuk role & permission |
| admin | Operasional (kendaraan, tipe, crew, jadwal), user, lihat role, media |
| crew | Read-only `vehicles.view` + `schedules.view` |

Hanya superadmin yang bisa memberi role `superadmin` atau mengubah/menghapus akun superadmin. Role `superadmin` tidak bisa diubah/dihapus, dan minimal satu akun superadmin harus tetap ada.

## Aturan Jadwal

- Satu record per kendaraan per tanggal (`vehicle_id` + `schedule_date` unik).
- `POST /schedules` menerima `schedule_date` **atau** `start_date` + `end_date`; rentang disimpan per tanggal.
- Input ulang tanggal yang sama meng-update record (bukan duplikat).
- Crew tidak boleh bertugas di dua kendaraan pada tanggal yang sama.
- Driver wajib punya SIM; kondektur tidak.
- Status jadwal: `ready`, `in_use`, `maintenance`, `off`.
- `GET /schedules/daily` mengembalikan semua kendaraan; yang belum punya record berstatus `ready` dengan `is_recorded=false`.

## Media

- Thumbnail kendaraan: disk `public`, maks. 5 MB (jpg/jpeg/png/webp).
- Dokumen kendaraan/crew: disk `private`, maks. 10 MB (jpg/jpeg/png/webp/pdf), diakses via signed URL 30 menit.
- Gallery membaca tabel `attachments`; file liar di disk tidak ditampilkan.

## Test

```bash
php artisan test
```

Test memakai SQLite in-memory (`phpunit.xml`).
