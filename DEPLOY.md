# Deploy Backend TransManagement ke VPS 43.157.197.42

Panduan ini **hanya untuk deploy**. Jangan mendesain ulang skema, route, atau kode aplikasi di sini.
Target: menaruh backend TransManagement di VPS `43.157.197.42` **tanpa menyentuh** POS, landing page, dan crew-backend yang sudah produksi.

---

## 1. Kondisi server (per 27 Nov 2026)

| Item | Nilai |
|---|---|
| CPU | 2 vCPU |
| RAM | 1.9 GB total, ~1.0 GB available, swap 1.9 GB (427 MB terpakai) |
| Disk | 40 GB, **21 GB free** |
| Container aktif | 7: POS prod, POS dev, Postgres, nginx, scheduler, certbot, landingpage |
| Postgres | 17.11, container `pos-backend-db-prod`, `max_connections=100` (terpakai 8) |

Budget aplikasi ini: **app 256 MB**, tanpa worker/scheduler (tidak ada queued job / scheduled task).
Total tambahan RAM ±256–300 MB. Masih muat, tapi VPS sudah mepet — pantau 24 jam pertama (`free -m`, swap).
Kalau swap terpakai > 1 GB terus-menerus: upgrade ke 4 GB atau pisah VPS.

---

## 2. Aturan wajib: nama unik (jangan generik)

Insiden landing page: service bernama `app` didaftarkan Compose sebagai DNS alias di network bersama, sehingga upstream nginx POS (`http://app:8080`, re-resolve tiap 10 detik) kadang nyasar ke container landing page → POS lemot/502 acak.

Untuk aplikasi ini:

- Service: `transmanagement-backend`
- Container: `transmanagement-backend-app`
- Alias network: `transmanagement-backend`

**Dilarang** memakai `app`, `app-dev`, `nginx`, `db`, `redis`, `scheduler`, atau nama generik lain.
Cek alias yang sudah ada sebelum deploy:

```bash
docker network inspect pos-backend-docker_pos-network \
  --format '{{range .Containers}}{{.Name}} {{end}}'
```

---

## 3. Penempatan

| Item | Nilai |
|---|---|
| Image | `ghcr.io/mitrasovadigitalsolutions/transmanagementbackend:latest` |
| Container | `transmanagement-backend-app` |
| Port host | **8090** → container 8080 |
| Network | external `pos-backend-docker_pos-network`, alias `transmanagement-backend` |
| Volume | `transmanagement-storage:/var/www/html/storage` |
| Direktori deploy | `/home/ubuntu/transmanagement-backend-docker/` |
| DB | `transmanagement_prod`, role `transmanagement`, di container Postgres yang sudah jalan |

Jangan membuat container Postgres baru. Sebelum memakai port 8090, pastikan belum terpakai:

```bash
ss -tlnp | grep ':8090' || echo 'port 8090 free'
```

---

## 4. Provisioning database (sekali, manual)

Jangan pakai user superuser `postgres` di `.env` aplikasi.

```bash
docker exec -it pos-backend-db-prod psql -U postgres.rlzufmgrrevfwzvtture -c \
  "CREATE ROLE transmanagement LOGIN PASSWORD '<password-kuat>';"
docker exec -it pos-backend-db-prod psql -U postgres.rlzufmgrrevfwzvtture -c \
  "CREATE DATABASE transmanagement_prod OWNER transmanagement;"
```

Test koneksi:

```bash
docker exec -e PGPASSWORD='<password-kuat>' pos-backend-db-prod \
  psql -U transmanagement -d transmanagement_prod -c 'select current_database(), current_user;'
```

Output harus `transmanagement_prod | transmanagement`. Kalau gagal, perbaiki dulu — jangan lanjut.

---

## 5. Env produksi

Buat `/home/ubuntu/transmanagement-backend-docker/.env` (jangan commit):

```dotenv
APP_NAME="TransManagement"
APP_ENV=production
APP_DEBUG=false
APP_KEY=<hasil php artisan key:generate --show>
APP_URL=http://43.157.197.42:8090

DB_CONNECTION=pgsql
DB_HOST=pos-backend-db-prod
DB_PORT=5432
DB_DATABASE=transmanagement_prod
DB_USERNAME=transmanagement
DB_PASSWORD=<password-kuat>

QUEUE_CONNECTION=sync
CACHE_STORE=database
SESSION_DRIVER=array
LOG_CHANNEL=stderr
FILESYSTEM_DISK=local

SUPERADMIN_EMAIL=admin@mitrasejatipersada.biz.id
SUPERADMIN_PASSWORD=<password-superadmin-kuat>
```

Catatan:

- `APP_URL` harus URL yang benar-benar dipakai user (signed URL dokumen dibuat dari sini). Kalau nanti pakai domain/HTTPS, ganti lalu recreate container.
- `LOG_CHANNEL=stderr` agar log masuk `docker logs`, tidak menumpuk file di volume.
- `SESSION_DRIVER=array` karena API-only (token Sanctum), tidak butuh tabel session.
- Entrypoint otomatis menjalankan `storage:link`, `config:cache`, `route:cache`, dan `migrate --force` setiap start. Tidak perlu jalankan manual.
- **Seeder tidak dijalankan otomatis.** Setelah container pertama kali Up dan migrasi sukses, jalankan sekali:

```bash
docker exec transmanagement-backend-app php artisan db:seed --class=RolePermissionSeeder --force
docker exec transmanagement-backend-app php artisan db:seed --class=SuperadminSeeder --force
```

- `SUPERADMIN_PASSWORD` wajib terisi saat `APP_ENV=production`, kalau kosong seeder gagal (by design).
- Setelah ubah `.env` (selain saat start container): `docker compose -f docker-compose.prod.yml up -d --force-recreate` karena `config:cache` mengunci env saat boot.

---

## 6. Menjalankan compose

```bash
mkdir -p /home/ubuntu/transmanagement-backend-docker
cd /home/ubuntu/transmanagement-backend-docker
# salin deploy/docker-compose.prod.yml dari repo ke sini
# isi .env seperti §5

# kalau image private, login dulu (PAT dengan scope read:packages)
echo '<PAT>' | docker login ghcr.io -u <username-github> --password-stdin

docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

Verifikasi cepat:

```bash
docker ps --filter name=transmanagement-backend
docker logs --tail 80 transmanagement-backend-app
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8090/api/v1/health   # 200
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8090/docs            # 200
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8090/openapi.json    # 200
```

`/docs` dan `/openapi.json` di-serve dari `public/openapi.json` + `resources/views/swagger.blade.php` yang ikut ke dalam image (jangan pernah menaruhnya di `.dockerignore`). `openapi.json` dibuat lokal dengan `composer openapi` — regenerate & commit sebelum push kalau route berubah, karena `dedoc/scramble` tidak ikut ter-install di image (`--no-dev`).

---

## 7. Storage & signed URL

Volume `transmanagement-storage` menutupi **seluruh** `/var/www/html/storage`:

- `storage/app/public` → thumbnail kendaraan (publik, lewat symlink `public/storage`).
- `storage/app/private` → dokumen STNK/KIR/SIM (privat, hanya lewat signed URL berlaku 30 menit).

Konsekuensi backup: **`storage/app` (public + private) wajib masuk backup**, bukan hanya `public/`. Kalau hanya `public` yang dibackup, semua dokumen legal hilang saat volume rusak.

---

## 8. Backup DB + storage (14 hari)

`/home/ubuntu/backups/transmanagement/backup.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
DIR=/home/ubuntu/backups/transmanagement
STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p "$DIR"

docker exec -e PGPASSWORD='<password-kuat>' pos-backend-db-prod \
  pg_dump -U transmanagement -d transmanagement_prod -Fc > "$DIR/db-$STAMP.dump"

# seluruh storage/app: thumbnails (public) dan dokumen (private)
docker exec transmanagement-backend-app \
  tar czf - -C /var/www/html/storage app \
  > "$DIR/storage-$STAMP.tar.gz"

test -s "$DIR/db-$STAMP.dump"
test -s "$DIR/storage-$STAMP.tar.gz"
find "$DIR" -maxdepth 1 -type f -mtime +14 -delete
echo "[$(date '+%F %T')] transmanagement backup ok"
```

Pasang cron harian (mis. `15 2 * * *`):

```cron
15 2 * * * /home/ubuntu/backups/transmanagement/backup.sh >> /home/ubuntu/backups/transmanagement/backup.log 2>&1
```

**Uji restore minimal sekali** ke database sementara:

```bash
docker exec pos-backend-db-prod psql -U postgres.rlzufmgrrevfwzvtture -c "CREATE DATABASE transmanagement_restore_test OWNER transmanagement;"
docker exec -i -e PGPASSWORD='<password-kuat>' pos-backend-db-prod \
  pg_restore -U transmanagement -d transmanagement_restore_test < "$DIR/db-<stamp>.dump"
docker exec -e PGPASSWORD='<password-kuat>' pos-backend-db-prod \
  psql -U transmanagement -d transmanagement_restore_test -c 'select count(*) from vehicles;'
docker exec pos-backend-db-prod psql -U postgres.rlzufmgrrevfwzvtture -c "DROP DATABASE transmanagement_restore_test;"
```

Storage: ekstrak `storage-<stamp>.tar.gz` ke volume sementara dan pastikan jumlah file `app/private/vehicles` + `app/private/crews` wajar.

---

## 9. CI/CD

- GitHub Actions (`.github/workflows/ghcr.yml`): job `test` (`php artisan test`, PHP 8.2 + sqlite) lalu job `build` push ke GHCR dengan tag `latest` dan `prod-<sha>`.
- **Tidak ada job deploy.** Deploy manual dari VPS.

```bash
# 0. di lokal: pastikan CI commit yang dipakai sudah sukses
gh run list --limit 3 --json databaseId,headSha,status,conclusion

# 1. backup
/home/ubuntu/backups/transmanagement/backup.sh

# 2. deploy
cd /home/ubuntu/transmanagement-backend-docker
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d --remove-orphans

# 3. verifikasi
docker ps --filter name=transmanagement-backend
docker logs --tail 80 transmanagement-backend-app
curl -s http://localhost:8090/api/v1/health
```

`gh run list` pernah mengembalikan run lama (race setelah push): cocokkan `headSha` dan pastikan `status=completed`, `conclusion=success`.

---

## 10. Checklist pasca deploy

- [ ] `docker ps` → `transmanagement-backend-app` **Up**, tidak restart-loop.
- [ ] `docker logs transmanagement-backend-app` → migrasi sukses, tidak ada error DB.
- [ ] `curl http://localhost:8090/api/v1/health` → 200 `{"success":true,"status":"ok"}`.
- [ ] `/docs` dan `/openapi.json` → 200.
- [ ] Login `POST /api/v1/auth/login` dengan akun superadmin → dapat token.
- [ ] Upload thumbnail kendaraan → URL `/storage/...` bisa dibuka.
- [ ] Upload dokumen (STNK/SIM) → signed URL bisa diunduh, dan **expired setelah 30 menit**.
- [ ] `docker compose -f docker-compose.prod.yml up -d --force-recreate` → file thumbnail + dokumen masih ada (volume persist).
- [ ] `docker stats --no-stream` → RAM < 256 MB; `free -m` available > 300 MB; `df -h /` sisa > 10 GB.
- [ ] POS, landing page, crew-backend dicek sekilas: tidak ada 502/error baru.

---

## 11. Domain & SSL (opsional)

Fase awal cukup `http://43.157.197.42:8090`. Kalau perlu domain:

1. Arahkan DNS ke `43.157.197.42`.
2. Tambah vhost di `/home/workspace/pos-backend-docker/docker/nginx.conf`:
   `listen 443 ssl` → `resolver 127.0.0.11 valid=10s; set $tm_upstream http://transmanagement-backend:8080; proxy_pass $tm_upstream;`
3. Ambil sertifikat dengan container `certbot` yang sudah ada.
4. Validasi **sebelum** reload — kalau `nginx -t` gagal, POS ikut down:

```bash
docker exec pos-backend-nginx-prod nginx -t
docker exec pos-backend-nginx-prod nginx -s reload
```

5. Update `APP_URL` ke `https://...`, lalu `up -d --force-recreate` (signed URL ikut berubah).

---

## 12. Rollback

1. **Kode**: pakai tag versi sebelumnya (`prod-<sha>`) — ubah `image:` di compose, lalu `pull` + `up -d`.
2. **DB**: restore dump terakhir:

```bash
docker exec -i -e PGPASSWORD='<password-kuat>' pos-backend-db-prod \
  pg_restore -U transmanagement -d transmanagement_prod --clean --if-exists \
  < /home/ubuntu/backups/transmanagement/db-<stamp>.dump
```

3. **Storage**: ekstrak `storage-<stamp>.tar.gz` ke volume kalau file ikut rusak:

```bash
VOL=$(docker inspect transmanagement-backend-app \
  --format '{{range .Mounts}}{{if eq .Destination "/var/www/html/storage"}}{{.Name}}{{end}}{{end}}')
docker run --rm -i -v "$VOL":/target alpine \
  tar xzf - -C /target < /home/ubuntu/backups/transmanagement/storage-<stamp>.tar.gz
```

4. Setelah rollback: health 200, cek log, cek POS/landing/crew.

Simpan minimal 2–3 image versi terakhir sebelum prune.

---

## 13. Monitoring & perawatan

- `docker stats --no-stream`, `free -m`, `df -h /` — minimal tiap hari di minggu pertama.
- Log: `docker logs --tail 200 transmanagement-backend-app` (stderr, tidak ada rotasi file).
- Image lama: `docker image prune -f && docker builder prune -f` — **jangan** `-a`, **jangan** `--volumes`.
- Tidak ada queue worker/scheduler: kalau suatu saat butuh, tambah service baru dengan nama `transmanagement-backend-worker`, bukan nama generik.

---

## 14. Larangan

- Jangan menyentuh container/volume/env POS, landing page, atau crew-backend.
- Jangan membuat container Postgres baru — pakai `pos-backend-db-prod`.
- Jangan memakai nama service/container/alias generik (`app`, `nginx`, `db`, `redis`, `scheduler`).
- Jangan `migrate:fresh`, `db:wipe`, atau seed ulang di produksi.
- Jangan build image di server (RAM mepet; build di CI).
- Jangan `docker system prune -a` atau `docker volume prune`.
- Jangan commit `.env`, password, atau `APP_KEY`.

---

## 15. Status deploy aktual (27 Nov 2026)

| Item | Nilai |
|---|---|
| Image/commit | `ghcr.io/mitrasovadigitalsolutions/transmanagementbackend:latest` (commit `5a1b5f9`) |
| Container | `transmanagement-backend-app`, Up, restart 0, OOM false |
| Port | `8090` (publik, health dicek dari luar → 200) |
| Direktori | `/home/ubuntu/transmanagement-backend-docker/` |
| DB | `transmanagement_prod` / role `transmanagement`, migrasi sudah jalan |
| Seeder | `RolePermissionSeeder` + `SuperadminSeeder` sudah dijalankan |
| Superadmin | `admin@mitrasejatipersada.biz.id` (password hanya di `.env` server) |
| Backup | `/home/ubuntu/backups/transmanagement/backup.sh`, cron `15 2 * * *`, uji restore OK |
| Health | `http://43.157.197.42:8090/api/v1/health` → 200 |
| Docs | `/docs`, `/openapi.json` → 200 |
| Worker/scheduler | Tidak ada (belum dibutuhkan) |

Password superadmin bisa dilihat oleh owner di server (jangan tempel di chat/log):

```bash
grep '^SUPERADMIN_PASSWORD=' /home/ubuntu/transmanagement-backend-docker/.env
```

Cek dampak ke layanan lain setelah deploy:

- POS prod/dev: `https://kspmitrasejatipersada.biz.id/up` dan `https://dev.kspmitrasejatipersada.biz.id/up` → 200.
- Landing page backend: `http://127.0.0.1:8088/api/v1/settings` (header `X-Site-Key`) → 200.
- Nginx, POS prod, landing page: tidak ada error/502 di log 10 menit setelah deploy.
- Semua container lama: restart 0, OOM false.
- Postgres: 8 koneksi (max 100), tidak ada container DB baru.
- Resource: app baru ±64 MB / 256 MB; RAM available ±984 MB; swap 445 MB; disk 47%.

Catatan: `APP_URL` masih `http://43.157.197.42:8090` (belum domain/SSL). Kalau nanti pakai domain, update `.env` + recreate container agar signed URL dokumen ikut berubah.
