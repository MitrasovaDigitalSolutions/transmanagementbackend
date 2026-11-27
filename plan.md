# TransManagement — Rencana Backend Manajemen Armada

Status: **implementasi selesai (M1–M4)** — 54 test hijau. Catatan operasional ada di bagian 11.

## 1. Tujuan & Scope

Backend API untuk mengelola armada kendaraan (bukan hanya bus), crew (driver/kondektur), dan jadwal ketersediaan armada per hari, plus role & permission.

**In scope**
- Auth token (Sanctum), user admin/operator/crew.
- Master tipe kendaraan (bus, elf, hiace, MPV, dll).
- CRUD kendaraan, crew, jadwal.
- Upload thumbnail kendaraan + dokumen penting (STNK/KIR) dan dokumen crew (foto SIM).
- Gallery media: lihat/cari semua gambar & dokumen, ganti file, edit label, hapus.
- Tampilan jadwal per kendaraan (kalender) dan per hari (kendaraan mana yang ready).
- Role & permission dinamis (admin bisa buat role + atur permission).
- Seeder role/permission + akun superadmin.
- Feature test untuk aturan bisnis utama.

**Out of scope (bisa menyusul)**
Frontend, booking/tiket penumpang, pembayaran, GPS tracking, work order maintenance, multi-tenant.

## 2. Stack & Konvensi

Mengikuti format LandingPage:

- Laravel 12, PHP 8.2, **PostgreSQL 14+ untuk production; SQLite untuk lokal & test**.
- `laravel/sanctum` untuk token API.
- `spatie/laravel-permission` untuk role & permission.
- PHPUnit + Pint.
- API-only, prefix `/api/v1`.
- Pencarian teks pakai `whereLike(..., caseSensitive: false)` supaya portable SQLite & PostgreSQL.
- Tipe kolom migrasi portable (tanpa `unsigned`/`year` MySQL-specific).
- File upload: thumbnail di disk `public`; dokumen sensitif (STNK/KIR/SIM) di disk `private`, diakses lewat signed URL sementara. Butuh `php artisan storage:link` untuk thumbnail.
- Controller di `app/Http/Controllers/Api/V1` (tanpa subfolder `Admin`, karena semua endpoint memang management; akses dibatasi permission).
- Model di `app/Models`, validasi inline di controller (konvensi LandingPage).
- Response wrapper konsisten:
  - Sukses: `{ "success": true, "message": "...", "data": ... }`
  - List: `{ "success": true, "data": [...], "meta": { current_page, last_page, per_page, total } }`
  - Error validasi: `{ "success": false, "message": "...", "errors": { ... } }`

## 3. Role & Permission

| Role | Hak |
| :--- | :--- |
| **superadmin** | Semua permission, termasuk kelola role & user. |
| **admin** | CRUD kendaraan, tipe kendaraan, crew, jadwal, kelola user, lihat daftar role (untuk assign), kelola gallery media. Tidak bisa CRUD role/permission. |
| **crew** | `vehicles.view`, `schedules.view` saja (read-only). Satu akun global untuk crew. |

Daftar permission:

```
vehicles.view          vehicles.create          vehicles.update          vehicles.delete
vehicle-types.view     vehicle-types.create     vehicle-types.update     vehicle-types.delete
crews.view             crews.create             crews.update             crews.delete
schedules.view         schedules.create         schedules.update         schedules.delete
users.view             users.create             users.update             users.delete
roles.view             roles.create             roles.update             roles.delete
media.view             media.update             media.delete
```

Catatan:
- Akun crew dibuat admin, bukan register publik.
- Permission dicek di server (middleware `permission:`), bukan hanya disembunyikan di frontend.

## 4. Data Model

### users
Tabel bawaan Laravel (`name`, `email`, `password`) + tabel Spatie (`roles`, `permissions`, pivot).

### vehicle_types
| Kolom | Tipe | Aturan |
| :--- | :--- | :--- |
| id | bigint | PK |
| name | string(50) | unik — mis. Bus, Elf, Hiace, MPV |
| description | string(255), nullable | keterangan |
| timestamps | | |

### vehicles
| Kolom | Tipe | Aturan |
| :--- | :--- | :--- |
| id | bigint | PK |
| vehicle_type_id | FK vehicle_types | restrict on delete |
| code | string(30) | unik — kode/nomor lambung |
| name | string(100) | nama kendaraan |
| plate_number | string(20) | unik — plat nomor |
| seat_capacity | unsignedSmallInteger | kapasitas kursi |
| year | smallInteger, nullable | tahun |
| status | string | `active` / `inactive`, default `active` |
| notes | text, nullable | catatan |
| timestamps | | |

### crews
| Kolom | Tipe | Aturan |
| :--- | :--- | :--- |
| id | bigint | PK |
| name | string(100) | |
| type | string | `driver` / `kondektur` |
| nik | string(16), nullable | unik |
| phone | string(30), nullable | |
| sim_number | string(30), nullable | wajib jika `type=driver` |
| sim_type | string(10), nullable | A / B1 / B2 / C; wajib jika driver |
| sim_expires_at | date, nullable | wajib jika driver |
| address | text, nullable | |
| status | string | `active` / `inactive`, default `active` |
| timestamps | | |

### schedules
| Kolom | Tipe | Aturan |
| :--- | :--- | :--- |
| id | bigint | PK |
| vehicle_id | FK vehicles | restrict on delete |
| schedule_date | date | |
| status | string | `ready` / `in_use` / `maintenance` / `off`, default `ready` |
| departure_time | time, nullable | jam/trip |
| destination | string(150), nullable | tujuan pemakaian |
| driver_id | FK crews, nullable | harus crew `driver` |
| kondektur_id | FK crews, nullable | harus crew `kondektur` |
| notes | text, nullable | alasan/keluhan |
| timestamps | | |

Constraint & index:
- **Unik `(vehicle_id, schedule_date)`** — satu record per kendaraan per hari.
- Index `(schedule_date, status)` untuk tampilan harian.
- `driver_id != kondektur_id`.

### attachments (polymorphic)
| Kolom | Tipe | Aturan |
| :--- | :--- | :--- |
| id | bigint | PK |
| attachable_type | string | morph map: `vehicle` / `crew` |
| attachable_id | bigint | |
| category | string | `thumbnail` / `document` |
| label | string(50), nullable | mis. STNK, KIR, SIM A |
| path | string | path di disk `public` |
| original_name | string(255) | nama file asli |
| mime_type | string(100) | |
| size | bigInteger | bytes |
| expires_at | date, nullable | masa berlaku dokumen |
| timestamps | | |

- Index `(attachable_type, attachable_id)`.
- Maksimal 1 `thumbnail` per kendaraan (upload baru mengganti yang lama).
- Crew hanya punya `document` (foto SIM), tanpa thumbnail profil.
- `thumbnail` disimpan di disk `public`; `document` di disk `private` dan diakses lewat signed URL (30 menit).

## 5. API Endpoints

### Auth
| Method | Path | Permission |
| :--- | :--- | :--- |
| POST | `/auth/login` | publik |
| GET | `/auth/me` | auth |
| POST | `/auth/logout` | auth |

### Tipe Kendaraan
| Method | Path | Permission |
| :--- | :--- | :--- |
| GET | `/vehicle-types` | `vehicle-types.view` |
| POST | `/vehicle-types` | `vehicle-types.create` |
| GET | `/vehicle-types/{id}` | `vehicle-types.view` |
| PUT | `/vehicle-types/{id}` | `vehicle-types.update` |
| DELETE | `/vehicle-types/{id}` | `vehicle-types.delete` |

### Kendaraan
| Method | Path | Permission |
| :--- | :--- | :--- |
| GET | `/vehicles?search=&status=&vehicle_type_id=&per_page=` | `vehicles.view` |
| POST | `/vehicles` | `vehicles.create` |
| GET | `/vehicles/{id}` | `vehicles.view` |
| PUT | `/vehicles/{id}` | `vehicles.update` |
| DELETE | `/vehicles/{id}` | `vehicles.delete` |

### Crew
| Method | Path | Permission |
| :--- | :--- | :--- |
| GET | `/crews?search=&type=&status=&per_page=` | `crews.view` |
| POST | `/crews` | `crews.create` |
| GET | `/crews/{id}` | `crews.view` |
| PUT | `/crews/{id}` | `crews.update` |
| DELETE | `/crews/{id}` | `crews.delete` |

Detail kendaraan & crew menyertakan `attachments`, dan kendaraan menyertakan `thumbnail_url`.

### Lampiran (File)
| Method | Path | Permission | Fungsi |
| :--- | :--- | :--- | :--- |
| POST | `/vehicles/{id}/attachments` | `vehicles.update` | Upload thumbnail atau dokumen kendaraan |
| POST | `/crews/{id}/attachments` | `crews.update` | Upload dokumen crew (foto SIM) |

Payload multipart:

```
category: thumbnail | document
label: STNK / KIR / SIM A / ...
expires_at: 2027-01-31 (opsional)
file: <file>
```

Validasi file:
- `thumbnail`: jpg/jpeg/png/webp, maks. 5 MB.
- `document`: jpg/jpeg/png/webp/pdf, maks. 10 MB.

Perilaku:
- File disimpan dengan nama acak: thumbnail di `storage/app/public/vehicles/{id}`, dokumen di `storage/app/private/vehicles/{id}` atau `crews/{id}`.
- Upload `thumbnail` baru otomatis menghapus thumbnail lama (file + row).
- Hapus kendaraan/crew ikut menghapus seluruh file & row attachment-nya.
- Response attachment berisi `id`, `category`, `label`, `url`, `original_name`, `mime_type`, `size`, `expires_at`.
- Crew hanya menerima thumbnail kendaraan; daftar dokumen disembunyikan kecuali user punya `media.view`.

### Gallery Media
| Method | Path | Permission | Fungsi |
| :--- | :--- | :--- | :--- |
| GET | `/media?q=&category=&attachable_type=&per_page=` | `media.view` | List + cari semua thumbnail/dokumen |
| PUT | `/media/{id}` | `media.update` | Edit `label` / `expires_at` |
| POST | `/media/{id}/replace` | `media.update` | Ganti isi file (metadata & owner tetap) |
| DELETE | `/media/{id}` | `media.delete` | Hapus file + row |
| GET | `/attachments/{id}/file` | signed URL | Akses file dokumen (tanpa bearer token) |

Perilaku gallery:
- Setiap item menyertakan `owner` (`vehicle`/`crew` + id + nama), `category`, `label`, `url`, `original_name`, `mime_type`, `size`, `expires_at`.
- `q` mencari di `label` dan `original_name`.
- `url` thumbnail = URL publik; `url` dokumen = signed URL berlaku 30 menit.
- `replace` memakai aturan tipe & ukuran yang sama dengan upload; file lama dihapus.
- Gallery hanya menampilkan lampiran yang terdaftar di DB (bukan scan file liar di disk).

### Jadwal
| Method | Path | Permission | Fungsi |
| :--- | :--- | :--- | :--- |
| GET | `/schedules?vehicle_id=&from=&to=&status=` | `schedules.view` | Kalender per kendaraan (list record) |
| GET | `/schedules/daily?date=YYYY-MM-DD` | `schedules.view` | Fokus 1 hari: semua kendaraan + status ready |
| POST | `/schedules` | `schedules.create` | Input 1 tanggal atau rentang |
| GET | `/schedules/{id}` | `schedules.view` | Detail |
| PUT | `/schedules/{id}` | `schedules.update` | Edit 1 record |
| DELETE | `/schedules/{id}` | `schedules.delete` | Hapus 1 record |

**POST `/schedules`** menerima salah satu:
- `schedule_date` (satu hari), atau
- `start_date` + `end_date` (rentang; backend membuat satu row per tanggal).

Contoh:

```json
{
  "vehicle_id": 1,
  "start_date": "2026-12-01",
  "end_date": "2026-12-03",
  "status": "in_use",
  "departure_time": "08:00",
  "destination": "Bandung",
  "driver_id": 2,
  "kondektur_id": 3,
  "notes": "Trip reguler"
}
```

Perilaku:
- Rentang disimpan per tanggal (`updateOrCreate`), jadi input ulang bersifat idempotent (tanggal yang sudah ada di-update).
- Semua tanggal diproses dalam satu transaksi; kalau satu tanggal bentrok, seluruh request di-rollback.
- Bentrok yang dicek: driver/kondektur sudah bertugas di kendaraan lain pada tanggal yang sama.

**GET `/schedules/daily`** mengembalikan semua kendaraan (termasuk yang belum punya record), dengan:

```json
{
  "vehicle": { "id": 1, "code": "B-01", "plate_number": "...", "vehicle_type": {...} },
  "schedule": null,
  "status": "ready",
  "is_recorded": false
}
```

Kendaraan tanpa record dianggap `ready` tapi `is_recorded=false`, supaya frontend bisa membedakan "sudah dicek ready" vs "belum diisi".

### User & Role
| Method | Path | Permission |
| :--- | :--- | :--- |
| GET | `/users` | `users.view` |
| POST | `/users` | `users.create` |
| GET | `/users/{id}` | `users.view` |
| PUT | `/users/{id}` | `users.update` |
| DELETE | `/users/{id}` | `users.delete` |
| GET | `/roles` | `roles.view` |
| POST | `/roles` | `roles.create` |
| GET | `/roles/{id}` | `roles.view` |
| PUT | `/roles/{id}` | `roles.update` |
| DELETE | `/roles/{id}` | `roles.delete` |
| GET | `/permissions` | `roles.view` |

- User dibuat admin; payload `roles: ["admin"]` (nama role).
- Role dibuat dengan `name` + `permissions: ["vehicles.view", ...]`.

## 6. Aturan Bisnis

1. Tipe kendaraan tidak bisa dihapus jika masih dipakai kendaraan (422).
2. Kendaraan/crew tidak bisa dihapus jika sudah dipakai di jadwal (422) — menjaga histori.
3. Role tidak bisa dihapus jika masih dipakai user (422).
4. User tidak bisa menghapus akunnya sendiri.
5. Crew `driver` wajib punya data SIM; `kondektur` tidak.
6. Satu crew tidak boleh ditugaskan ke dua kendaraan pada tanggal yang sama.
7. Driver dan kondektur dalam satu record jadwal tidak boleh orang yang sama.
8. Perubahan `vehicle_id`/`schedule_date` lewat PUT tetap dicek unik.
9. `status` jadwal tidak otomatis berubah saat crew diisi — admin yang menentukan.
10. Password tidak pernah dikembalikan di response.
11. Tipe & ukuran file divalidasi di server; file disimpan dengan nama acak, bukan nama asli.
12. Hapus attachment menghapus file fisik; hapus kendaraan/crew menghapus semua lampirannya.
13. Role crew (read-only) tidak bisa upload/hapus lampiran dan tidak bisa membuka gallery/dokumen.
14. Dokumen sensitif hanya bisa diakses lewat signed URL sementara; thumbnail boleh publik.
15. Gallery hanya menampilkan lampiran yang terdaftar di DB (bukan scan file liar).
16. Hanya superadmin yang boleh memberi role `superadmin` atau mengubah/menghapus akun superadmin. Role `superadmin` sendiri tidak bisa diubah/dihapus, dan minimal satu akun superadmin harus tetap ada.
17. Admin hanya boleh melihat daftar role (`roles.view`), tidak boleh CRUD role/permission.

## 7. Struktur Folder

```
app/Models/               User, VehicleType, Vehicle, Crew, Schedule, Attachment
app/Http/Controllers/Api/V1/
                          AuthController, VehicleTypeController, VehicleController,
                          CrewController, ScheduleController, UserController, RoleController,
                          AttachmentController, MediaController
database/migrations/      vehicle_types, vehicles, crews, schedules, attachments, permission tables
database/seeders/         RolePermissionSeeder, SuperadminSeeder
routes/api.php
tests/Feature/            Auth, VehicleType, Vehicle, Crew, Schedule, Attachment, Media, UserRole
```

## 8. Rencana Test

- Auth: login/logout/me; token invalid.
- Permission: crew hanya boleh GET kendaraan & jadwal (POST/PUT/DELETE 403); admin tidak bisa kelola role.
- Tipe kendaraan: CRUD, nama unik, delete ditolak jika dipakai kendaraan.
- Kendaraan: CRUD, validasi unik code/plate, filter search/status/tipe, delete ditolak jika ada jadwal.
- Crew: CRUD, validasi SIM driver, filter type/status, delete ditolak jika dipakai jadwal.
- Jadwal:
  - Input satu tanggal.
  - Input rentang → jumlah row sesuai, input ulang meng-update bukan duplikat.
  - Driver/kondektur bentrok → 422 dan tidak ada row yang tersimpan (rollback).
  - Tipe crew salah (kondektur di kolom driver) → 422.
  - Unique kendaraan+tanggal.
  - Daily view: kendaraan tanpa record muncul `ready` + `is_recorded=false`; record `maintenance` menimpa.
- Lampiran: upload thumbnail & dokumen (fake Storage), thumbnail baru menggantikan lama, file non-image ditolak untuk thumbnail, PDF diterima untuk dokumen, hapus attachment menghapus file, crew read-only 403.
- Gallery: list + search `q` & filter category, replace file (file lama hilang), edit label/expires_at, hapus, signed URL dokumen bisa diakses tanpa token dan expired setelah 30 menit.
- User & role: CRUD, assign role, role dipakai tidak bisa dihapus, tidak bisa hapus diri sendiri.

## 9. Milestone

| Tahap | Isi | Selesai jika |
| :--- | :--- | :--- |
| M1 | Scaffold Laravel 12 + Sanctum + Spatie (SQLite lokal, PostgreSQL production), auth, role/permission, user CRUD | Login & permission test hijau |
| M2 | CRUD tipe kendaraan + kendaraan + crew + upload lampiran + gallery media | CRUD, validasi, upload & gallery test hijau |
| M3 | Jadwal single/range + daily view + aturan konflik | Test jadwal hijau |
| M4 | Seeder, `storage:link`, README endpoint, Pint, full test run | `php artisan test` hijau |

## 10. Asumsi (perlu dikoreksi kalau salah)

- **Satu kendaraan = satu jadwal per hari.** Kalau nanti butuh beberapa trip per hari, unique diganti `(vehicle_id, schedule_date, departure_time)` atau dibuat tabel `trips` terpisah.
- Kendaraan tanpa record di daily view dianggap `ready` (ditandai `is_recorded=false`).
- Master tipe kendaraan hanya `name` + `description`; belum ada tarif/default kapasitas per tipe.
- Hanya superadmin yang bisa kelola role/permission; admin mengelola operasional + user.
- Database: SQLite untuk lokal & test, PostgreSQL untuk production. Migrasi dibuat portable; tidak ada raw SQL spesifik database.
- File disimpan di disk `public`; `storage:link` wajib dijalankan saat deploy.
- Thumbnail kendaraan maksimal 1; dokumen bebas jumlahnya. Crew hanya dokumen (foto SIM), tanpa foto profil.
- Gallery berbasis tabel `attachments` (bukan scan disk seperti LandingPage), jadi file di luar DB tidak muncul.
- Thumbnail disajikan publik; dokumen lewat signed URL 30 menit. Belum ada virus scan/watermark.

## 11. Status Implementasi

- Auth Sanctum, role/permission Spatie, CRUD user & role: selesai.
- Master tipe kendaraan, CRUD kendaraan, crew: selesai.
- Jadwal single/range, daily view, aturan konflik & rollback: selesai.
- Lampiran (thumbnail kendaraan, dokumen STNK/KIR, SIM crew) + gallery media: selesai.
- Seeder role + superadmin (password production wajib diisi): selesai.
- Test: `php artisan test` → 54 passed.
- Deviasi/penyesuaian saat implementasi:
  - Admin diberi `roles.view` agar bisa memilih role saat membuat user; CRUD role tetap hanya superadmin.
  - Hanya superadmin yang boleh memberi role `superadmin` atau mengubah/menghapus akun superadmin.
  - Dokumen crew hanya dimuat untuk user dengan `media.view`.
- PostgreSQL production belum diuji koneksi runtime (belum ada kredensial); migrasi memakai tipe portable dan test berjalan di SQLite.
