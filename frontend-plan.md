# TransManagement — Frontend Handover Plan

Dokumen ini untuk frontend engineer yang mengerjakan UI TransManagement. Backend sudah live dan stabil; fokus dokumen ini adalah kontrak API, struktur layar, aturan UX, dan urutan pengerjaan.

- Repository backend: https://github.com/MitrasovaDigitalSolutions/transmanagementbackend
- Backend plan: [plan.md](plan.md)
- Deploy/ops: [DEPLOY.md](DEPLOY.md)

## 1. Status Backend

| Item | Nilai |
| :--- | :--- |
| API production | `http://43.157.197.42:8090/api/v1` |
| Swagger UI | `http://43.157.197.42:8090/docs` |
| OpenAPI JSON | `http://43.157.197.42:8090/openapi.json` |
| Health check | `GET /api/v1/health` → 200 |
| Auth | Sanctum Bearer token |
| Database | PostgreSQL production, SQLite lokal/test |
| Bahasa | API mengembalikan pesan error dalam Bahasa Indonesia |

Catatan: Swagger membantu eksplorasi, tetapi **query filter tidak semuanya tercantum** di OpenAPI. Dokumen ini adalah acuan kontrak untuk frontend.

## 2. Scope Produk

Aplikasi manajemen armada:

- Master tipe kendaraan (Bus, Elf, Hiace, MPV, dll).
- Kendaraan: data unit, status aktif, thumbnail, dokumen STNK/KIR.
- Crew: driver dan kondektur, data SIM, dokumen SIM.
- Jadwal: ketersediaan kendaraan per hari, crew bertugas, jam/tujuan pemakaian.
- Gallery media: cari, lihat, ganti, edit metadata, hapus lampiran.
- User & role: admin mengelola user; superadmin mengelola role/permission.

Dua pengalaman utama:

1. **Admin/superadmin** — dashboard desktop untuk operasional penuh.
2. **Crew** — satu akun global, read-only, fokus melihat kendaraan dan jadwal hari ini dari HP.

## 3. Stack Rekomendasi

Mengikuti ekosistem LandingPage:

- **Next.js App Router + TypeScript**.
- **Tailwind CSS** (v4 seperti LandingPage).
- **TanStack Query v5** untuk cache/invalidasi data server.
- **react-hook-form + zod** untuk form.
- **date-fns** untuk tanggal/kalender.
- **lucide-react** untuk ikon, **sonner** untuk toast.
- **shadcn/ui** (Radix) opsional untuk tabel, dialog, select, form.
- **Vitest + React Testing Library**, **Playwright** untuk E2E.

Kalau tim frontend sudah punya stack lain, kontrak API di dokumen ini tetap berlaku. Yang penting: App Router/SSR tidak wajib; SPA juga bisa.

## 4. Environment

```env
# Kalau frontend memanggil API langsung dari browser:
NEXT_PUBLIC_API_URL=http://43.157.197.42:8090/api/v1

# Kalau memakai BFF/proxy Next.js (disarankan):
API_URL=http://43.157.197.42:8090/api/v1
```

- Jangan menaruh token/password di `NEXT_PUBLIC_*`.
- Untuk development lokal backend: `php artisan serve` → `http://localhost:8000/api/v1`.
- Node.js 20+.

## 5. Auth & Session

### Alur

1. `POST /auth/login` dengan `{ email, password }`.
2. Response:
   ```json
   {
     "success": true,
     "message": "Login berhasil.",
     "data": {
       "token": "1|xxxxxxxx",
       "user": { "id": 1, "name": "Superadmin", "email": "...", "roles": ["superadmin"] }
     }
   }
   ```
3. Setelah login, panggil `GET /auth/me` untuk mendapatkan `permissions`.
4. Semua request terproteksi memakai header `Authorization: Bearer <token>`.
5. `POST /auth/logout` menghapus token aktif.
6. Jika response `401`, hapus session dan redirect ke `/login`.

### Penyimpanan token

- **Rekomendasi:** BFF Next.js.
  - Route handler `/api/auth/login` memanggil backend, lalu menyimpan token di cookie `httpOnly`, `secure` (production), `sameSite=lax`.
  - Route handler proxy meneruskan request ke backend dengan Bearer token dari cookie.
  - Token tidak pernah masuk JavaScript browser.
- **Alternatif cepat:** simpan token di `sessionStorage`/`localStorage`. Lebih sederhana, tapi rentan XSS. Jangan pilih ini untuk production.
- Token tidak punya masa berlaku/refresh token. Logout adalah satu-satunya invalidasi dari sisi user.

### Aturan login

- Login dibatasi **10 percobaan/menit**. Tampilkan pesan "Terlalu banyak percobaan, coba lagi sebentar."
- Tidak ada register, forgot password, atau reset password.
- Akun crew adalah **satu akun global**, bukan akun per individu crew.
- User dibuat oleh admin/superadmin.

## 6. Role & Permission

| Permission | superadmin | admin | crew |
| :--- | :---: | :---: | :---: |
| `vehicles.view` | ✅ | ✅ | ✅ |
| `vehicles.create/update/delete` | ✅ | ✅ | ❌ |
| `vehicle-types.*` | ✅ | ✅ | ❌ |
| `crews.*` | ✅ | ✅ | ❌ |
| `schedules.view` | ✅ | ✅ | ✅ |
| `schedules.create/update/delete` | ✅ | ✅ | ❌ |
| `users.*` | ✅ | ✅ | ❌ |
| `roles.view` | ✅ | ✅ | ❌ |
| `roles.create/update/delete` | ✅ | ❌ | ❌ |
| `media.*` | ✅ | ✅ | ❌ |

Catatan:

- Admin boleh **melihat** daftar role (untuk memilih role saat membuat user), tetapi tidak boleh CRUD role/permission.
- Hanya superadmin yang boleh memberi role `superadmin`, mengubah, atau menghapus akun superadmin.
- Role `superadmin` tidak bisa diubah/dihapus; minimal satu akun superadmin harus ada.
- Backend selalu menegakkan permission. UI hanya menyembunyikan tombol/menu; jangan andalkan UI saja.
- Gunakan `permissions` dari `/auth/me` untuk helper `can('vehicles.create')`, bukan nama role.

## 7. Struktur Halaman

```text
/login

/                         Dashboard (admin/superadmin)
/vehicles                 List kendaraan
/vehicles/new             Form kendaraan
/vehicles/[id]            Detail + edit + thumbnail + dokumen
/vehicle-types            Master tipe kendaraan
/crews                    List crew
/crews/new                Form crew
/crews/[id]               Detail + edit + dokumen SIM
/schedules                Kalender per kendaraan
/schedules/daily          Fokus satu hari (semua kendaraan)
/media                    Gallery media
/users                    Manajemen user (admin/superadmin)
/roles                    Manajemen role (superadmin; admin read-only)
/profile                  Profil + logout
```

Sidebar crew hanya: **Jadwal**, **Kendaraan**, **Profil**. Crew tidak melihat menu lain.

## 8. Spesifikasi Layar

### 8.1 Login

- Form email + password.
- Error 422 → tampilkan pesan dari `errors.email` / `message`.
- Error 429 → pesan rate limit.
- Setelah sukses, redirect:
  - crew → `/schedules/daily`
  - admin/superadmin → `/`
- Jangan simpan password di state yang persist.

### 8.2 Dashboard (admin/superadmin)

Kartu ringkasan hari ini:

- Total kendaraan: `GET /vehicles?per_page=1` → `meta.total`.
- Total crew: `GET /crews?per_page=1` → `meta.total`.
- Jadwal hari ini: `GET /schedules/daily?date=YYYY-MM-DD` → `meta.total`, `meta.ready`, `meta.recorded`.
- Daftar kendaraan maintenance/off hari ini dari data daily.
- Opsional: dokumen kedaluwarsa < 30 hari dari `GET /media?category=document&per_page=100` (hitung di client; hanya 100 item pertama).

Aksi cepat: tambah kendaraan, tambah crew, buka jadwal hari ini.

### 8.3 Tipe Kendaraan

- List sederhana (tanpa pagination): nama, deskripsi, jumlah kendaraan (bisa dari `vehicles` filter atau hitung client-side).
- Form create/edit: `name` (unik, max 50), `description` (max 255).
- Delete: jika masih dipakai kendaraan → 422 "Tipe kendaraan masih dipakai."
- Digunakan sebagai select di form kendaraan.

### 8.4 Kendaraan — List

Filter:

- `q` — cari code/nama/plate.
- `status` — `active` / `inactive`.
- `vehicle_type_id`.
- `per_page` (1–100, default 20).

Kolom: thumbnail, code, nama, plat, tipe, kapasitas, tahun, status, aksi.

- Urutan backend: terbaru (`id` desc).
- Status badge: `active` hijau, `inactive` abu.
- Klik baris → detail/edit.
- Delete: konfirmasi; jika kendaraan dipakai jadwal → 422 dan tampilkan pesan.

### 8.5 Kendaraan — Form & Detail

Field:

| Field | Wajib | Aturan |
| :--- | :---: | :--- |
| vehicle_type_id | ✅ | harus ada di master |
| code | ✅ | unik, max 30 |
| name | ✅ | max 100 |
| plate_number | ✅ | unik, max 20 |
| seat_capacity | ✅ | integer ≥ 1 |
| year | ❌ | 1900..tahun+1 |
| status | ❌ | `active`/`inactive`, default active |
| notes | ❌ | text |

Detail kendaraan menampilkan:

- Data kendaraan + `vehicle_type` + `thumbnail_url`.
- `attachments`: thumbnail dan dokumen (hanya jika user punya `media.view`; crew hanya thumbnail).
- Tombol upload thumbnail (ganti otomatis thumbnail lama).
- Tabel dokumen: label, masa berlaku, link `url`, aksi hapus/ganti lewat gallery.
- Peringatan dokumen kedaluwarsa/kurang dari 30 hari.

Upload:

- `POST /vehicles/{id}/attachments` multipart.
- `category=thumbnail` → jpg/jpeg/png/webp max 5 MB.
- `category=document` → jpg/jpeg/png/webp/pdf max 10 MB.
- Field: `label`, `expires_at` (YYYY-MM-DD), `file`.
- Setelah upload, invalidate query detail.

### 8.6 Crew — List

Filter: `q` (nama/nik/phone), `type` (`driver`/`kondektur`), `status`, `per_page`.

Kolom: nama, tipe, NIK, telepon, SIM (nomor/tipe/expiry), status, aksi.

- Badge driver/kondektur.
- Highlight SIM yang kedaluwarsa atau < 30 hari.
- Urutan nama A–Z.

### 8.7 Crew — Form & Detail

Field:

| Field | Wajib | Aturan |
| :--- | :---: | :--- |
| name | ✅ | max 100 |
| type | ✅ | `driver` / `kondektur` |
| nik | ❌ | 16 digit, unik |
| phone | ❌ | max 30 |
| sim_number | driver ✅ | max 30 |
| sim_type | driver ✅ | `A`, `B1`, `B2`, `C` |
| sim_expires_at | driver ✅ | date |
| address | ❌ | text |
| status | ❌ | `active`/`inactive` |

- Jika `type=kondektur`, sembunyikan field SIM.
- Jika mengganti driver → kondektur, field SIM tidak wajib.
- Detail memuat `documents` (foto SIM) untuk user dengan `media.view`.
- Upload dokumen crew: `POST /crews/{id}/attachments`, hanya `category=document`; label mis. "SIM A".
- Delete crew yang sudah dipakai jadwal → 422.

### 8.8 Jadwal — Dua Tampilan

#### A. Kalender per kendaraan

- Pilih kendaraan + bulan.
- Fetch:
  ```
  GET /schedules?vehicle_id=1&from=2026-12-01&to=2026-12-31&per_page=100
  ```
- Backend hanya mengembalikan record yang ada. Hari tanpa record **tidak dikirim**; frontend menampilkan sebagai `ready` + `is_recorded=false` (konsisten dengan daily view).
- Cell status:
  - `ready` — hijau
  - `in_use` — biru
  - `maintenance` — amber
  - `off` — abu
- Klik cell:
  - Ada record → buka detail/edit/hapus.
  - Belum ada → buka form create dengan tanggal terisi.
- Tampilkan crew, jam, tujuan di cell/tooltip.
- Legenda status wajib.

#### B. Daily view (fokus satu hari)

- Date picker, default hari ini.
- `GET /schedules/daily?date=YYYY-MM-DD`.
- Response item:
  ```ts
  {
    vehicle: Vehicle,
    schedule: Schedule | null,
    status: "ready" | "in_use" | "maintenance" | "off",
    is_recorded: boolean
  }
  ```
- Filter client-side: status, tipe kendaraan, search plat/code.
- Ringkasan: total, ready, recorded.
- Crew melihat versi read-only; admin bisa klik untuk edit.
- Urutan mengikuti `code` kendaraan.

#### Form jadwal

Create (`POST /schedules`):

```json
{
  "vehicle_id": 1,
  "schedule_date": "2026-12-01",
  "status": "in_use",
  "departure_time": "08:00",
  "destination": "Bandung",
  "driver_id": 2,
  "kondektur_id": 3,
  "notes": "Trip reguler"
}
```

Atau rentang:

```json
{
  "vehicle_id": 1,
  "start_date": "2026-12-01",
  "end_date": "2026-12-03",
  "status": "maintenance",
  "notes": "Servis"
}
```

Aturan:

- Pilih **salah satu**: `schedule_date` atau `start_date` + `end_date`.
- Rentang disimpan per tanggal. Tanggal yang sudah ada akan **di-update** (upsert), bukan duplikat.
- Tampilkan warning: "Rentang akan menimpa jadwal yang sudah ada di tanggal tersebut."
- Crew double-booking per tanggal → 422; seluruh rentang rollback.
- Driver harus crew tipe driver; kondektur harus crew tipe kondektur; tidak boleh orang yang sama.
- Satu kendaraan hanya boleh satu record per tanggal.
- `departure_time` format `HH:mm`.
- Update satu record: `PUT /schedules/{id}` (partial fields, tapi method PUT).
- Delete: `DELETE /schedules/{id}`.

### 8.9 Gallery Media

- `GET /media?q=&category=&attachable_type=&per_page=`.
  - `q` mencari label/nama file.
  - `category` = `thumbnail` | `document`.
  - `attachable_type` = `vehicle` | `crew`.
- Item berisi metadata + `url` + `owner { type, id, name }`.
- Grid card:
  - Thumbnail: `<img src={url}>` (URL publik).
  - Dokumen PDF: ikon PDF, klik buka `url` (signed, 30 menit).
  - Label, tipe pemilik, tanggal kedaluwarsa, ukuran file.
- Aksi:
  - Edit metadata: `PUT /media/{id}` (`label`, `expires_at`).
  - Ganti file: `POST /media/{id}/replace` multipart `file` (aturan tipe/ukuran mengikuti category).
  - Hapus: `DELETE /media/{id}` (hapus file + row; konfirmasi).
- **Tidak ada upload langsung dari gallery.** Upload selalu dari detail kendaraan/crew. Gallery hanya melihat/mengganti/menghapus.
- Signed URL bisa kedaluwarsa; jika 403/410, refetch list.

### 8.10 Users

- List: `GET /users?per_page=` (tanpa search backend; filter client-side jika perlu).
- Kolom: nama, email, roles, created_at.
- Create: `name`, `email`, `password` min 8, `roles` array nama role.
- Edit: field opsional; `roles` mengganti seluruh role user.
- Admin tidak boleh memberi role `superadmin` (403); sembunyikan opsi itu.
- Admin tidak boleh mengubah/menghapus akun superadmin.
- Tidak bisa menghapus akun sendiri (422).
- Ambil daftar role dari `GET /roles` (admin boleh `roles.view`).
- Password tidak pernah dikembalikan API.

### 8.11 Roles (superadmin)

- List semua role + permissions.
- Create/update: nama role + array permission names.
- Permission list: `GET /permissions`.
- Matriks permission dikelompokkan per modul (`vehicles.*`, `crews.*`, dll).
- Role `superadmin` immutable: tombol edit/delete disabled.
- Delete role yang masih dipakai user → 422.
- Admin hanya bisa melihat daftar role (read-only).

### 8.12 Profile

- Tampilkan nama, email, roles, permissions.
- Logout.
- Ganti nama/email/password memakai `PUT /users/{id}`; hanya role dengan `users.update` (admin/superadmin). Crew tidak bisa mengganti password sendiri.

## 9. Kontrak API Ringkas

### Wrapper

```ts
type ApiResponse<T> = {
  success: true;
  message?: string;
  data: T;
};

type Paginated<T> = {
  success: true;
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};
```

Error validasi (422):

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": { "field": ["Pesan error"] }
}
```

Error lain (401/403/404/429/422 guard) bisa berupa `{ "message": "..." }`. Selalu tampilkan `message` jika ada.

### Tipe Data

```ts
type VehicleStatus = "active" | "inactive";
type CrewType = "driver" | "kondektur";
type ScheduleStatus = "ready" | "in_use" | "maintenance" | "off";
type AttachmentCategory = "thumbnail" | "document";

interface VehicleType {
  id: number;
  name: string;
  description: string | null;
}

interface Vehicle {
  id: number;
  vehicle_type_id: number;
  code: string;
  name: string;
  plate_number: string;
  seat_capacity: number;
  year: number | null;
  status: VehicleStatus;
  notes: string | null;
  thumbnail_url: string | null;
  vehicle_type?: VehicleType;
  attachments?: Attachment[];
  created_at: string;
  updated_at: string;
}

interface Crew {
  id: number;
  name: string;
  type: CrewType;
  nik: string | null;
  phone: string | null;
  sim_number: string | null;
  sim_type: "A" | "B1" | "B2" | "C" | null;
  sim_expires_at: string | null;
  address: string | null;
  status: VehicleStatus;
  documents?: Attachment[];
  created_at: string;
  updated_at: string;
}

interface Schedule {
  id: number;
  vehicle_id: number;
  schedule_date: string; // ISO date-time
  status: ScheduleStatus;
  departure_time: string | null; // "08:00" atau "08:00:00"
  destination: string | null;
  driver_id: number | null;
  kondektur_id: number | null;
  notes: string | null;
  vehicle?: Vehicle;
  driver?: Crew | null;
  kondektur?: Crew | null;
  created_at: string;
  updated_at: string;
}

interface Attachment {
  id: number;
  category: AttachmentCategory;
  label: string | null;
  original_name: string;
  mime_type: string;
  size: number;
  expires_at: string | null;
  url: string; // thumbnail publik / dokumen signed 30 menit
  owner?: { type: "vehicle" | "crew"; id: number; name: string };
}

interface User {
  id: number;
  name: string;
  email: string;
  roles: string[];
  permissions?: string[];
}

interface Role {
  id: number;
  name: string;
  permissions: string[];
}
```

Catatan: `path` internal attachment tidak perlu dipakai di UI; gunakan `url`.

### Endpoint

| Method | Path | Permission | Keterangan |
| :--- | :--- | :--- | :--- |
| POST | `/auth/login` | publik | Login, rate limit 10/menit |
| GET | `/auth/me` | auth | User + roles + permissions |
| POST | `/auth/logout` | auth | Hapus token aktif |
| GET | `/vehicle-types` | `vehicle-types.view` | Semua tipe, tanpa pagination |
| POST | `/vehicle-types` | `vehicle-types.create` | Create |
| GET | `/vehicle-types/{id}` | `vehicle-types.view` | Detail |
| PUT | `/vehicle-types/{id}` | `vehicle-types.update` | Update |
| DELETE | `/vehicle-types/{id}` | `vehicle-types.delete` | 422 jika dipakai |
| GET | `/vehicles` | `vehicles.view` | Filter `q,status,vehicle_type_id,per_page` |
| POST | `/vehicles` | `vehicles.create` | Create |
| GET | `/vehicles/{id}` | `vehicles.view` | Detail + attachments |
| PUT | `/vehicles/{id}` | `vehicles.update` | Update parsial |
| DELETE | `/vehicles/{id}` | `vehicles.delete` | 422 jika ada jadwal |
| POST | `/vehicles/{id}/attachments` | `vehicles.update` | Upload thumbnail/dokumen |
| GET | `/crews` | `crews.view` | Filter `q,type,status,per_page` |
| POST | `/crews` | `crews.create` | Create |
| GET | `/crews/{id}` | `crews.view` | Detail + documents |
| PUT | `/crews/{id}` | `crews.update` | Update parsial |
| DELETE | `/crews/{id}` | `crews.delete` | 422 jika ada jadwal |
| POST | `/crews/{id}/attachments` | `crews.update` | Upload dokumen SIM |
| GET | `/schedules` | `schedules.view` | Filter `vehicle_id,status,from,to,per_page` |
| GET | `/schedules/daily` | `schedules.view` | `date` wajib |
| POST | `/schedules` | `schedules.create` | Single/range |
| GET | `/schedules/{id}` | `schedules.view` | Detail |
| PUT | `/schedules/{id}` | `schedules.update` | Update parsial |
| DELETE | `/schedules/{id}` | `schedules.delete` | Hapus |
| GET | `/media` | `media.view` | Filter `q,category,attachable_type,per_page` |
| PUT | `/media/{id}` | `media.update` | Edit label/expires_at |
| POST | `/media/{id}/replace` | `media.update` | Ganti file |
| DELETE | `/media/{id}` | `media.delete` | Hapus file + row |
| GET | `/attachments/{id}/file` | signed URL | Akses dokumen tanpa Bearer |
| GET | `/users` | `users.view` | Pagination |
| POST | `/users` | `users.create` | Create user |
| GET | `/users/{id}` | `users.view` | Detail |
| PUT | `/users/{id}` | `users.update` | Update user/roles/password |
| DELETE | `/users/{id}` | `users.delete` | Guard self/superadmin |
| GET | `/roles` | `roles.view` | Semua role |
| POST | `/roles` | `roles.create` | Create role |
| GET | `/roles/{id}` | `roles.view` | Detail |
| PUT | `/roles/{id}` | `roles.update` | Superadmin immutable |
| DELETE | `/roles/{id}` | `roles.delete` | 422 jika dipakai |
| GET | `/permissions` | `roles.view` | Semua permission |

### Payload Penting

Kendaraan create:

```json
{
  "vehicle_type_id": 1,
  "code": "B-01",
  "name": "Mercedes OH 1626",
  "plate_number": "B 1234 XYZ",
  "seat_capacity": 40,
  "year": 2020,
  "status": "active",
  "notes": null
}
```

Crew driver:

```json
{
  "name": "Budi",
  "type": "driver",
  "nik": "3201234567890001",
  "phone": "0812...",
  "sim_number": "1234-5678",
  "sim_type": "B1",
  "sim_expires_at": "2027-05-31",
  "status": "active"
}
```

Jadwal single/range: lihat bagian 8.8.

Upload lampiran (multipart):

```text
category: thumbnail | document
label: STNK / KIR / SIM A
expires_at: 2027-01-31
file: <file>
```

User:

```json
{
  "name": "Operator",
  "email": "operator@example.com",
  "password": "minimal8",
  "roles": ["admin"]
}
```

Role:

```json
{
  "name": "operator",
  "permissions": ["vehicles.view", "schedules.view"]
}
```

## 10. Aturan & Quirk Backend

1. **Satu kendaraan = satu jadwal per tanggal.** Belum ada multi-trip per hari.
2. `POST /schedules` rentang bersifat **upsert**: tanggal yang sudah ada di-update. Beri warning di UI.
3. Bentrok crew dicek per tanggal dan seluruh rentang rollback jika salah satu gagal.
4. Deletion guard:
   - Tipe kendaraan dipakai kendaraan → 422.
   - Kendaraan/crew dipakai jadwal → 422.
   - Role dipakai user → 422.
   - User tidak bisa hapus diri sendiri; admin tidak bisa hapus superadmin; minimal satu superadmin.
5. `PUT` dipakai untuk update parsial (bukan `PATCH`).
6. Tidak ada endpoint search global, sorting server-side, bulk action, atau refresh token.
7. `schedule_date` diserialisasi sebagai ISO date-time (`2026-12-01T00:00:00.000000Z`). Untuk date-only, ambil `slice(0, 10)` atau format dengan date-fns; jangan menggeser tanggal karena timezone.
8. `departure_time` bisa `"08:00"` atau `"08:00:00"`; normalisasi ke `HH:mm`.
9. `expires_at` dan `sim_expires_at` adalah date-only.
10. Dokumen memakai signed URL 30 menit; jangan cache lama. Thumbnail publik.
11. Crew tidak punya akses ke detail crew, media, dokumen, user, atau role.
12. `GET /vehicles/{id}` untuk crew hanya memuat thumbnail, bukan dokumen.
13. Login rate limit 10/menit.
14. CORS backend saat ini `Access-Control-Allow-Origin: *`; kalau production frontend punya domain, sebaiknya backend di-restrict.
15. Tidak ada notifikasi/email/websocket.

## 11. Upload File & Signed URL

- Gunakan `FormData`; jangan set `Content-Type` manual (browser mengatur boundary).
- Validasi client-side sebelum upload:
  - Thumbnail: jpg/jpeg/png/webp, ≤ 5 MB.
  - Dokumen: jpg/jpeg/png/webp/pdf, ≤ 10 MB.
- Progress bar + disable submit saat upload.
- Setelah upload/ganti/hapus, invalidate query kendaraan/crew/media.
- Thumbnail baru otomatis menggantikan thumbnail lama.
- Dokumen crew hanya bisa `document` (bukan thumbnail).
- Signed URL dokumen bisa dibuka tanpa Bearer; jangan buat request dengan header Authorization ke URL signed (tidak perlu, tapi tidak masalah).
- Jika memakai BFF di Vercel: body limit 4.5 MB, sementara dokumen sampai 10 MB. Untuk production, backend sebaiknya sudah HTTPS agar upload langsung dari browser tidak kena mixed content/limit.

## 12. Date & Time

- Timezone UI: `Asia/Jakarta`.
- Gunakan date-fns; hindari `new Date("YYYY-MM-DD")` mentah karena bisa UTC shift.
- Helper yang disarankan:
  ```ts
  const toDateOnly = (iso: string) => iso.slice(0, 10);
  const toTime = (value: string | null) => value?.slice(0, 5) ?? "";
  ```
- Kalender: mulai Senin, tampilkan bulan + navigasi prev/next.
- Range jadwal: batasi UI mis. maksimal 90 hari untuk menghindari input tidak sengaja (backend tidak membatasi).
- Tampilkan tanggal kedaluwarsa dokumen/SIM dengan badge:
  - expired (merah)
  - < 30 hari (amber)
  - aman (netral)

## 13. UI/UX & Aksesibilitas

- Loading: skeleton untuk tabel/kartu; jangan blank.
- Empty state: pesan + CTA (kecuali crew).
- Error: toast + inline error untuk form.
- Konfirmasi hapus untuk semua aksi destruktif.
- Status tidak hanya warna: sertakan teks/badge.
- Form: label eksplisit, error di bawah field, fokus ke field pertama yang error.
- Tabel: bisa horizontal scroll di mobile; untuk crew gunakan card list.
- Pagination: tampilkan "x–y dari total".
- Search: debounce 300 ms.
- Filter disimpan di URL query agar bisa di-refresh/share.
- Jangan tampilkan tombol yang tidak diizinkan permission.
- Bahasa UI: Indonesia.

## 14. Rencana Test Frontend

Unit/component:

- Helper permission (`can`), formatter tanggal/jam, status badge.
- Form validation kendaraan/crew/jadwal.
- Komponen upload (tipe/ukuran).
- Empty/loading/error state.

E2E (Playwright) minimal:

1. Login superadmin → dashboard.
2. Login admin → tidak melihat menu role CRUD.
3. Login crew → hanya jadwal/kendaraan, tidak ada tombol edit.
4. CRUD tipe kendaraan.
5. CRUD kendaraan + upload thumbnail + dokumen.
6. CRUD crew driver (SIM wajib) + kondektur.
7. Jadwal single + range; cek upsert dan conflict 422.
8. Daily view menampilkan kendaraan tanpa record sebagai ready.
9. Media: search, replace, edit label, delete.
10. User: create dengan role, admin tidak bisa assign superadmin.
11. Role: superadmin create/update; role in use delete 422.
12. Delete guard kendaraan/crew.
13. 401 → redirect login; 403 → halaman forbidden.
14. Upload melebihi limit → pesan validasi.

Gunakan backend staging/production dengan data uji; bersihkan data setelah test.

## 15. Milestone

| Tahap | Isi | Estimasi |
| :--- | :--- | :--- |
| F0 | Setup Next.js, Tailwind, API client, env, layout | 0.5–1 hari |
| F1 | Login, auth/session, permission guard, sidebar | 1–2 hari |
| F2 | Tipe kendaraan, kendaraan + upload, crew + SIM | 3–4 hari |
| F3 | Jadwal kalender + daily view + form single/range | 3–5 hari |
| F4 | Gallery media + signed URL + replace/delete | 2–3 hari |
| F5 | Users + roles + permission matrix | 2 hari |
| F6 | Responsive crew, error states, testing, deploy | 2–3 hari |

Total ± 2–3 minggu untuk satu frontend engineer, tergantung desain.

## 16. Deployment & Risiko

Backend saat ini **HTTP** (`http://43.157.197.42:8090`), belum HTTPS/domain. Ini memengaruhi frontend:

- Frontend HTTPS memanggil API HTTP → **mixed content diblokir**.
- Signed URL dokumen dibuat dari `APP_URL` backend (`http://43.157.197.42:8090`); `<img>`/`<iframe>` di halaman HTTPS juga diblokir.

Opsi:

1. **Rekomendasi:** pasang domain + SSL untuk backend dulu (nginx existing), lalu frontend memanggil HTTPS.
2. Frontend + BFF di VPS yang sama: browser → HTTPS frontend → server-side proxy → backend HTTP internal. Upload diproxy di server (tidak kena limit Vercel). Dokumen juga harus diproxy atau backend diberi HTTPS.
3. Vercel + BFF: upload > 4.5 MB akan kena limit serverless; jadi backend HTTPS lebih aman.

Kalau deploy frontend di VPS yang sama:

- Gunakan nama service/container unik, mis. `transmanagement-frontend`; **jangan** `app`, `nginx`, `db`, atau nama generik (pernah menyebabkan POS 502).
- Jangan mengubah container POS/landing/crew.
- nginx: `nginx -t` sebelum reload.
- Domain yang disarankan: `transmanagement.kspmitrasejatipersada.biz.id` (konfirmasi ke owner).

Environment production frontend:

```env
API_URL=https://api-domain-atau-http-internal
NEXT_PUBLIC_APP_NAME=TransManagement
# cookie secret jika memakai BFF
```

Jangan commit `.env`.

## 17. Handover Checklist

- [ ] Baca `plan.md` (backend) dan dokumen ini.
- [ ] Dapat akses repo frontend + akses API production.
- [ ] Dapat akun uji: superadmin, admin, crew (minta owner; jangan taruh di repo).
- [ ] Konfirmasi stack (Next.js?) dan hosting (Vercel/VPS).
- [ ] Konfirmasi domain + SSL backend (blocker untuk production HTTPS).
- [ ] Konfirmasi desain/branding dan bahasa (Indonesia).
- [ ] Siapkan data contoh: tipe kendaraan, 2–3 kendaraan, driver/kondektur, jadwal beberapa hari.
- [ ] Sepakati definisi selesai: semua flow di §8 + test §14 hijau.
- [ ] Review bersama: crew mobile view dan error handling jadwal.

## 18. Pertanyaan untuk Owner

1. Frontend pakai Next.js App Router atau stack lain?
2. Hosting: Vercel, VPS yang sama, atau lainnya?
3. Domain frontend/backend dan rencana SSL?
4. Siapa pemilik akun crew global (email/password disimpan di mana)?
5. Apakah butuh multi-trip per kendaraan per hari? Backend saat ini membatasi satu jadwal per kendaraan per tanggal.
6. Apakah butuh modul di luar scope (booking penumpang, pembayaran, GPS, maintenance work order)?
7. Preferensi desain: mengikuti branding Mitrasova/LandingPage atau design system baru?

---

Dokumen ini fokus pada kontrak dan alur. Kalau ada perubahan backend (endpoint/field), update `plan.md` dan regenerate OpenAPI dengan `composer openapi`.
