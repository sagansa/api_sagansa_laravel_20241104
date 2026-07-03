# Employee Location Tracking

Fitur pelacakan lokasi pegawai: admin dapat (1) memicu permintaan lokasi
on-demand dan (2) menerima update lokasi periodik (~2 jam) dari pegawai yang
memasang aplikasi.

## Arsitektur singkat

```
apps/admin (tombol "Lacak Lokasi" / halaman peta)
  → POST /services/api/admin/track-location/{user}
  → services/api: buat LocationRequest(pending) + kirim FCM high-priority ke device pegawai
  → Flutter: FCM background handler → ambil GPS → POST /location {request_id, lat, lng}
  → services/api: LocationRequest → fulfilled, simpan EmployeeLocation
  → apps/admin: polling lihat hasil di peta

Periodic 2 jam:
  Flutter workmanager → ambil GPS → POST /location {source=periodic} → EmployeeLocation
```

## Tabel baru

- `employee_locations` (mysql/sagansa) — titik lokasi (lat/lng/accuracy/source/request_id/captured_at)
- `location_requests` (mysql/sagansa) — record permintaan on-demand (status pending|fulfilled|failed|timeout)
- `device_tokens` (mysql_auth/sagansa_user) — FCM token per device pegawai

## Endpoint

Mobile (`auth:sanctum`):
- `POST /location` — unggah lokasi `{latitude, longitude, accuracy?, source, request_id?, captured_at?}`
- `POST /device-tokens` — daftar FCM token `{token, device_id?}`
- `DELETE /device-tokens` — hapus token `{token}` (saat logout)

Admin (`auth:sanctum` + middleware `admin`):
- `POST /admin/track-location/{user}` — picu on-demand
- `GET  /admin/track-location/{location_request}` — cek status satu permintaan
- `GET  /admin/employee-locations` — lokasi terbaru tiap pegawai (untuk peta)
- `GET  /admin/employee-locations/{user}` — riwayat satu pegawai

## Konfigurasi Firebase (wajib)

FCM dipakai sebagai jalur server→device untuk on-demand.

1. Buat/buka project di [Firebase Console](https://console.firebase.google.com) → aktifkan Cloud Messaging.
2. **Server (backend):** Project Settings → Service Accounts → generate new private key (JSON). Simpan di path terenkripsi di server, lalu set env:
   ```env
   FIREBASE_CREDENTIALS=/abs/path/to/firebase-service-account.json
   FIREBASE_PROJECT=id-project-firebase
   ```
   Config: `config/firebase.php` (dari `kreait/laravel-firebase`).
3. **Device (mobile):** download `google-services.json` → taruh di `mobiles/sagansa/android/app/`.

## Deployment backend

```bash
composer install            # termasuk kreait/laravel-firebase
php artisan migrate         # buat 3 tabel baru
```

Scheduler (untuk timeout cleanup request on-demand >5 menit):
```cron
* * * * * cd /path/to/services/api && php artisan schedule:run >> /dev/null 2>&1
```

## Catatan

- Referensi user antar-DB memakai loose `unsignedBigInteger` (tanpa FK enforced) karena tabel `users` ada di DB `mysql_auth` terpisah, mengikuti pola presences.
- Koneksi DB auth di `services/api/config/database.php` bernama `mysql_auth` (underscore) untuk konsistensi dengan konvensi model & dengan `apps/admin`.
