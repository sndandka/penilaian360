# Sistem Penilaian 360° — Dinas Pekerjaan Umum Bina Marga Provinsi Jawa Timur

Aplikasi web **Sistem Penilaian Kinerja 360 Derajat** berbasis struktur organisasi pemerintahan. Setiap pegawai dinilai dari empat sudut pandang — **diri sendiri, atasan, rekan kerja, dan bawahan** — dengan pembobotan adaptif sesuai kategori jabatan dan aturan *re-weighting* Perka BKN.

## Teknologi
- **Backend**: PHP Native (PDO + MySQL/MariaDB), 15 endpoint REST API, autentikasi berbasis session, RBAC 3 role (`super_admin`, `admin`, `pegawai`), proteksi CSRF, dan audit log
- **Frontend**: HTML + CSS + JavaScript murni (vanilla JS tanpa framework, Chart.js untuk visualisasi statistik, dukungan mode terang & gelap)
- **Database**: MySQL / MariaDB, 13 tabel ternormalisasi (`database/schema.sql`)
- **Laporan & Import Data**: Export PDF menggunakan library **FPDF** & cetak dokumen HTML resmi (`kop_surat`), export CSV, serta import data pegawai Excel (`.xlsx`)

## Aktor Sistem
| Role | Deskripsi | Kredensial Login |
|---|---|---|
| `super_admin` | Akses penuh: seluruh modul operasional, konfigurasi bobot & informasi aplikasi, manajemen akun Admin/Super Admin, dan audit log (terpisah dari data pegawai) | Username: `superadmin` |
| `admin` | Akses operasional: master data kepegawaian, rancangan & periode penilaian, mapping penilai 360°, hasil penilaian, monitoring progres, dan cetak laporan (terpisah dari data pegawai) | Username: `admin` |
| `pegawai` | Mengisi penilaian yang ditugaskan (Mode Standar & Matriks 360), melihat & mencetak hasil penilaian diri sendiri, serta mengelola profil & password akun | NIP: 18 digit |

## Alur Penilaian 360°
1. **Admin / Super Admin** menyiapkan master data (`unit_kerja`, `jabatan`, `pegawai`, serta garis atasan langsung — manual atau via Import Excel).
2. **Admin / Super Admin** merancang instrumen penilaian (Kategori → Indikator → Pertanyaan) dan membuat periode penilaian (Tahunan, Semester, Triwulan, Bulanan, atau Kustom; seluruh pegawai atau khusus golongan tertentu).
3. **Admin / Super Admin** memetakan penilai (`generate` otomatis berimbang via `backend/helpers/mapping_generator.php` atau mapping manual per pegawai/relasi).
4. **Pegawai** login dan mengerjakan penilaian yang ditugaskan selama periode aktif.
5. **Kalkulasi Otomatis & Monitoring**: Hasil penilaian (`hasil_penilaian`) dan predikat dihitung otomatis saat seluruh penilaian selesai/submit final maupun saat periode ditutup (`simpanHasilPenilaian()` di `backend/helpers/results_calculator.php`), dengan opsi **Hitung Ulang Semua** pada halaman Hasil Penilaian, pemantauan progres + deteksi pola skor seragam (*straight-lining*), serta export PDF/CSV.
6. **Pegawai** melihat dashboard skor kompetensi per aspek dan mencetak laporan hasil penilaian dirinya.

### Bobot Penilaian Default (Konfigurasi Sistem)
- **Pejabat Struktural** (memiliki bawahan): Atasan Langsung **50%**, Rekan Kerja **15%**, Bawahan **25%**, Diri Sendiri **10%**.
- **Pegawai Pelaksana / Fungsional** (tanpa bawahan): Atasan Langsung **50%**, Rekan Kerja **40%**, Diri Sendiri **10%**.
- Jika ada kelompok penilai yang kosong/belum menilai, sistem otomatis menerapkan *dynamic re-weighting* sesuai Perka BKN.

### Predikat Default
`Sangat Baik` (≥ 91), `Baik` (≥ 76), `Cukup` (≥ 61), `Kurang` (≥ 51), `Sangat Kurang` (< 51).

## Instalasi Cepat

1. Buat database `penilaian360` dan import skema tabel (DDL 13 tabel):
   ```bash
   mysql -u root -p < database/schema.sql
   ```

2. Import data master & instrumen penilaian awal:
   ```bash
   mysql -u root -p penilaian360 < database/seed_data_lengkap.sql
   ```
   File ini mengisi konfigurasi sistem default, 64 unit kerja hierarki 3 tingkat (Dinas, Sekretariat/Bidang/UPT, dan Seksi/Subbagian), 27 jabatan, 2 akun admin sistem (`superadmin` & `admin`), serta 6 kategori, 6 indikator, dan 40 butir pertanyaan penilaian (20 butir khusus bawahan menilai atasan dan 20 butir untuk atasan, rekan kerja, & diri sendiri).

3. Import data pegawai melalui menu **Data Pegawai > Import Excel** menggunakan template kosong `frontend/assets/template_import_pegawai.xlsx` (atau file data sampel `Data_Pegawai_Lengkap_DPU_BinaMarga_Jatim.xlsx`).

4. *(Opsional)* Setelah data 480 pegawai (ID `1–480`) tersedia di tabel `pegawai`, Anda dapat meng-import satu periode penilaian tahunan lengkap (`PRD-2025-LENGKAP`, 6.733 mapping selesai, 134.660 jawaban, 480 hasil penilaian, dan audit log):
   ```bash
   mysql -u root -p penilaian360 < database/seed_periode_lengkap.sql
   ```

5. Sesuaikan kredensial database di `backend/config/database.php` jika diperlukan (default: host `localhost`, dbname `penilaian360`, user `root`, password kosong).

6. Jalankan Apache & MySQL/MariaDB melalui XAMPP, lalu buka:
   ```
   http://localhost/Sistem_Penilaian_360_DPU_BinaMarga_Jatim/frontend/landing.html
   ```
   Halaman login dapat diakses langsung di `frontend/index.html`.

   Untuk uji cepat dengan PHP built-in server tanpa Apache:
   ```bash
   php -S localhost:8000 -t .
   ```
   lalu buka `http://localhost:8000/frontend/landing.html`.

7. **Akun Login Sistem Default**:
   | Username / NIP | Role | Password | Keterangan |
   |---|---|---|---|
   | `superadmin` | Super Admin | `superbmprovjatim1` | Akses penuh seluruh konfigurasi sistem, manajemen akun admin, audit log, dan operasional |
   | `admin` | Admin | `adminbmprovjatim` | Akses operasional data pegawai, unit kerja/jabatan, periode, mapping, hasil, dan laporan |
   | NIP Pegawai (18 digit) | Pegawai | `password` | Default saat pembuatan/import akun pegawai; disarankan segera diganti melalui menu Konfigurasi Akun |

> [!IMPORTANT]
> Data unit kerja, jabatan, dan kop surat default dapat disesuaikan dengan kebutuhan instansi melalui aplikasi maupun `backend/config/kop_surat.php` (teks & path logo untuk export PDF) serta template cetak HTML di `frontend/admin/cetak_pegawai.html` dan `frontend/pegawai/cetak_hasil.html`.

## Modul & Endpoint API

### Umum
| Modul | Halaman Frontend | Endpoint API |
|---|---|---|
| Landing Page | `frontend/landing.html` | `backend/api/auth.php?action=me` |
| Login / Logout | `frontend/index.html` | `backend/api/auth.php` |

### Master Data & Penilaian (Admin & Super Admin)
| Modul | Halaman Frontend | Endpoint API |
|---|---|---|
| Dashboard Admin | `frontend/admin/dashboard.html` | `pegawai.php`, `periode.php`, `monitoring.php` |
| Data Pegawai (+ Import Excel) | `frontend/admin/pegawai.html` | `backend/api/pegawai.php`, `pegawai_import.php` |
| Unit Kerja & Jabatan | `frontend/admin/master.html` | `backend/api/unit_kerja.php`, `jabatan.php` |
| Periode Penilaian | `frontend/admin/periode.html` | `backend/api/periode.php` |
| Mapping Penilai 360° | `frontend/admin/mapping.html` | `backend/api/mapping.php` |
| Rancang Penilaian (Kategori, Indikator, Pertanyaan) | `frontend/admin/kriteria.html` | `backend/api/criteria.php` |
| Hasil Penilaian (+ Cetak Pegawai) | `frontend/admin/hasil.html`, `cetak_pegawai.html` | `backend/api/results.php` |
| Monitoring Progres & Pola Skor | `frontend/admin/monitoring.html` | `backend/api/monitoring.php` |
| Cetak Laporan (PDF/CSV) | `frontend/admin/laporan.html` | `backend/api/reports.php` |

### Khusus Super Admin
| Modul | Halaman Frontend | Endpoint API |
|---|---|---|
| Audit Log | `frontend/admin/audit.html` | `backend/api/monitoring.php?action=log` |
| Konfigurasi Sistem, Bobot 360° & Manajemen Akun Admin | `frontend/admin/konfigurasi.html` | `backend/api/config.php`, `backend/api/users.php` |

### Pegawai
| Modul | Halaman Frontend | Endpoint API |
|---|---|---|
| Dashboard Pegawai | `frontend/pegawai/dashboard.html` | `periode.php`, `results.php`, `evaluations.php?action=tugas` |
| Kerjakan Penilaian | `frontend/pegawai/penilaian.html` | `backend/api/evaluations.php` |
| Hasil Penilaian Saya (+ Cetak Hasil) | `frontend/pegawai/hasil.html`, `cetak_hasil.html` | `backend/api/results.php` |
| Konfigurasi Akun (Profil & Password) | `frontend/pegawai/konfigurasi.html` | `backend/api/profile.php` |

## Keamanan yang Diterapkan
- **Password Hash**: `password_hash()` bcrypt (`PASSWORD_BCRYPT`), tidak pernah disimpan atau ditampilkan dalam plain-text
- **Session Management**: cookie `HttpOnly`, `SameSite=Lax`, `Secure` otomatis di HTTPS; `session_regenerate_id()` saat login
- **RBAC (3 Role)**: setiap endpoint memvalidasi role melalui `backend/helpers/auth_check.php` — `requireSuperAdmin()` (konfigurasi sistem, manajemen akun admin, audit log), `requireAdmin()` (`super_admin` & `admin`), dan `requireLogin()`
- **CSRF Protection**: token per-sesi (`backend/config/csrf.php`), divalidasi pada request mutasi (POST/PUT/DELETE) via header `X-CSRF-Token`
- **Prepared Statement**: seluruh query dinamis menggunakan PDO parameter binding
- **Validasi Input**: `requireFields()`, `cleanStr()`, `validateNumberRange()`, `validateEmailIfPresent()` pada `backend/helpers/response.php`
- **Audit Log**: tabel `audit_log` mencatat aktivitas create/update/delete, login, mapping, kalkulasi hasil, dan submit penilaian beserta `data_sebelum`/`data_sesudah` (JSON), IP, dan user agent

## Struktur Folder
```text
Sistem_Penilaian_360_DPU_BinaMarga_Jatim/
├── Data_Pegawai_Lengkap_DPU_BinaMarga_Jatim.xlsx
├── README.md
├── database/
│   ├── schema.sql                    # DDL database & 13 tabel ternormalisasi
│   ├── seed_data_lengkap.sql         # Data master awal, akun default, & 40 instrumen pertanyaan
│   └── seed_periode_lengkap.sql      # Seed lengkap Periode Tahunan 2025 (PRD-2025-LENGKAP)
├── backend/
│   ├── config/
│   │   ├── database.php              # Koneksi PDO
│   │   ├── session.php               # Konfigurasi session & CORS
│   │   ├── csrf.php                  # Helper CSRF token
│   │   └── kop_surat.php             # Konfigurasi teks & path logo kop surat PDF
│   ├── helpers/
│   │   ├── response.php              # Helper JSON response & validasi input
│   │   ├── auth_check.php            # RBAC 3 role & logAudit()
│   │   ├── mapping_generator.php     # Auto-mapping penilai 360° seimbang
│   │   └── results_calculator.php    # Kalkulasi bobot fleksibel, re-weighting BKN, & predikat
│   ├── lib/fpdf/                     # Library FPDF untuk export PDF
│   └── api/                          # 15 endpoint REST API
└── frontend/
    ├── landing.html                  # Halaman landing informasi sistem
    ├── index.html                    # Halaman login
    ├── assets/
    │   ├── css/style.css             # Stylesheet utama (Light & Dark Mode + responsive)
    │   ├── js/                       # api.js, auth.js, searchable-select.js
    │   ├── logo/                     # OIP.webp, OIP.png, Landingpage.jpeg
    │   └── template_import_pegawai.xlsx
    ├── admin/                        # 12 halaman Admin & Super Admin (termasuk cetak_pegawai.html)
    └── pegawai/                      # 5 halaman Pegawai (termasuk cetak_hasil.html)
```
