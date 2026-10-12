# SPK PT CISAS

Sistem Pendukung Keputusan prioritas pemeliharaan enam motor mesin mixer PT CISAS menggunakan metode **AHP** dan **TOPSIS** berbasis web.

## Peran Pengguna
- **Administrator**: **otoritas sistem tertinggi**. Mengelola akun pengguna, data motor, periode pemeriksaan, memantau seluruh pemeriksaan, melihat hasil AHP/TOPSIS, riwayat perhitungan, riwayat pemeliharaan, laporan, serta log aktivitas untuk kebutuhan kontrol dan pemulihan administratif sistem.
- **Supervisor Maintenance**: **otoritas keputusan teknis maintenance**. Memantau dan memvalidasi pemeriksaan motor, melakukan pembobotan AHP, menjalankan TOPSIS, serta menetapkan rekomendasi/tindak lanjut.
- **Teknisi Maintenance**: **pelaksana operasional**. Mengisi dan memperbaiki pemeriksaan motor serta memperbarui pelaksanaan tindak lanjut yang ditugaskan.

> Hierarki otoritas sistem adalah **Administrator > Supervisor Maintenance > Teknisi Maintenance**. Namun, prinsip pemisahan tugas tetap digunakan: Administrator tidak mengambil alih keputusan teknis Supervisor dan tidak mengubah hasil pemeriksaan Teknisi sebagai proses normal.

## Alur Sistem
1. Administrator menyiapkan data motor dan periode pemeriksaan.
2. Teknisi mengisi pemeriksaan C1-C5 untuk A1-A6.
3. Supervisor memvalidasi pemeriksaan.
4. AHP diproses setelah data pemeriksaan siap dan lengkap.
5. Sistem menghitung bobot kriteria dan Rasio Konsistensi (CR).
6. TOPSIS dapat diproses jika AHP konsisten (CR <= 0,10).
7. Sistem menghasilkan nilai preferensi dan ranking A1-A6.
8. Supervisor menetapkan rekomendasi dan Teknisi melaksanakan tindak lanjut.

## AHP
Input perbandingan menggunakan **intensitas utama skala Saaty 1, 3, 5, 7, dan 9 beserta nilai kebalikannya** agar penilaian Supervisor lebih ringkas dan mudah dibaca.

Urutan perhitungan:

`matriks perbandingan -> jumlah kolom -> normalisasi kolom -> rata-rata baris -> lambda maksimum -> CI -> CR`

## TOPSIS
Urutan perhitungan:

`matriks keputusan -> normalisasi vektor -> matriks terbobot -> solusi ideal positif/negatif -> jarak solusi -> nilai preferensi -> ranking`

C1-C5 diperlakukan sebagai **benefit terhadap urgensi pemeliharaan**. Nilai yang lebih tinggi berarti kontribusi terhadap prioritas pemeliharaan semakin besar, bukan berarti kondisi motor semakin baik.

## Menu Utama
### Pemeriksaan
- **Pemeriksaan Motor**: input pemeriksaan oleh Teknisi dan monitoring oleh Supervisor.
- **Validasi Pemeriksaan**: keputusan validasi oleh Supervisor.
- **Riwayat Pemeliharaan**: rekomendasi dan perkembangan tindak lanjut.

### Perhitungan SPK
- **Pembobotan AHP**
- **Pemeringkatan TOPSIS**
- **Riwayat Perhitungan**

### Pelaporan
Tersedia empat laporan utama:
1. Laporan Pemeriksaan
2. Laporan Pembobotan AHP
3. Laporan Perhitungan TOPSIS
4. Laporan Prioritas & Tindak Lanjut

Dari Laporan Prioritas & Tindak Lanjut, pengguna dapat membuka **Laporan Akhir**, memeriksanya terlebih dahulu, lalu mencetaknya jika diperlukan.

## Database Penelitian Agustus 2026
Database utama berada di:

`database/spk_cisas.sqlite`

Data penelitian Agustus 2026 juga tersedia dalam:

`database/seed_research_august_2026.sql`

Hasil AHP pada data penelitian saat ini memiliki **CR = 0,0551403825** dan dinyatakan konsisten. TOPSIS menghasilkan urutan prioritas:

`A2 > A4 > A6 > A3 > A1 > A5`

## Menjalankan Sistem
1. Pastikan PHP tersedia pada PATH Windows dan ekstensi `pdo_sqlite` aktif.
2. Jalankan `run.bat`.
3. Buka `http://localhost:8000` jika browser tidak terbuka otomatis.

Jika database belum tersedia, jalankan `setup_database.bat`.

## Backup Database
Jalankan `backup_database.bat`. File backup akan disimpan ke folder `backups` dengan timestamp.

## Pengujian
- Pengujian algoritma dan workflow: `php tests/calculation/run.php`
- Audit database: `php tests/system_audit.php`
- Menjalankan seluruh pengujian: `audit_system.bat`

## Struktur Penting
- `app/` — konfigurasi, helper, dan service.
- `public/` — halaman aplikasi dan aset web.
- `templates/` — komponen layout bersama.
- `database/` — schema, seed, migrasi, dan database SQLite.
- `tests/` — pengujian algoritma, workflow, dan integritas data.


## Penyempurnaan workflow skripsi

- AHP dan TOPSIS tetap dapat dikoreksi oleh Supervisor pada periode Aktif; sistem memberi peringatan bila hasil sudah digunakan untuk tindak lanjut.
- Jika perbandingan AHP benar-benar berubah, hasil TOPSIS aktif dibersihkan agar wajib dihitung ulang dengan bobot terbaru; riwayat perhitungan lama tetap tersimpan.
- Tindak lanjut yang melewati target dan belum selesai ditandai **Terlambat** pada dashboard/riwayat.
- `tests/research_august_2026.php` mereproduksi hasil AHP -> TOPSIS periode penelitian dari data mentah.
