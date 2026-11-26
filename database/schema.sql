-- =========================================================================
-- SISTEM PENILAIAN 360 - SKEMA DATABASE v3
-- Dinas Pekerjaan Umum Bina Marga Provinsi Jawa Timur
-- =========================================================================
-- Diperbarui: Disesuaikan dengan data aktual pegawai (DUMMY PEGAWAI BINA MARGA TR.xlsx)
--
-- Perubahan utama dari v2:
--  1. unit_kerja.jenis_unit  : ditambah 'kelompok_fungsional', dihapus 'sub_bagian'
--                              diganti 'sub_bagian_tata_usaha'
--  2. jabatan.jenis_jabatan  : diubah dari 4 nilai menjadi 3 nilai sesuai data
--                              ('struktural','fungsional','pelaksana')
--  3. jabatan.eselon         : hanya kode eselon (II.a, III.a, III.b, IV.a)
--                              tanpa label panjang
--  4. pegawai.status_kepegawaian : ditambah 'PPPK_Paruh_Waktu'
--  5. pegawai.status_aktif   : ditambah 'meninggal'
--  6. Seed data awal (konfigurasi_sistem, unit_kerja, jabatan, users admin,
--     kategori, indikator, dan pertanyaan) dipisahkan ke:
--     database/seed_data_lengkap.sql
-- =========================================================================

CREATE DATABASE IF NOT EXISTS penilaian360
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE penilaian360;

SET FOREIGN_KEY_CHECKS = 0;

-- =========================================================================
-- 1. UNIT_KERJA
-- Hierarki 3 tingkat sesuai data:
--   Tingkat 1 (ES II)  : dinas
--   Tingkat 2 (ES III) : sekretariat | bidang | upt | upt_lab
--   Tingkat 3 (ES IV)  : seksi | sub_bagian_tata_usaha | subbagian |
--                        kelompok_fungsional
-- kepala_unit_id ditambahkan lewat ALTER TABLE di bawah (dependensi melingkar).
-- =========================================================================
CREATE TABLE unit_kerja (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    kode_unit        VARCHAR(30)  NOT NULL UNIQUE,
    nama_unit        VARCHAR(200) NOT NULL,
    jenis_unit       ENUM(
                         'dinas',
                         'sekretariat',
                         'bidang',
                         'upt',
                         'upt_lab',
                         'seksi',
                         'sub_bagian_tata_usaha',
                         'subbagian',
                         'kelompok_fungsional'
                     ) NOT NULL DEFAULT 'seksi',
    parent_id        INT  NULL COMMENT 'Unit kerja induk (self-reference untuk hierarki organisasi)',
    kepala_unit_id   INT  NULL COMMENT 'Diisi via ALTER TABLE setelah tabel pegawai dibuat',
    alamat           TEXT,
    status           ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_unitkerja_parent FOREIGN KEY (parent_id) REFERENCES unit_kerja(id) ON DELETE SET NULL,
    INDEX idx_unitkerja_parent (parent_id)
);

-- =========================================================================
-- 2. JABATAN
-- jenis_jabatan disederhanakan menjadi 3 nilai sesuai data aktual xlsx:
--   'Jabatan Struktural'  → 'struktural'
--   'Jabatan Fungsional'  → 'fungsional'
--   'Jabatan Pelaksana'   → 'pelaksana'
-- eselon menyimpan kode saja (II.a / III.a / III.b / IV.a).
-- =========================================================================
CREATE TABLE jabatan (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    kode_jabatan   VARCHAR(30)  NOT NULL UNIQUE,
    nama_jabatan   VARCHAR(200) NOT NULL,
    jenis_jabatan  ENUM('struktural','fungsional','pelaksana') NOT NULL DEFAULT 'pelaksana',
    eselon         VARCHAR(10)  NULL COMMENT 'Kode eselon: II.a, III.a, III.b, IV.a — NULL untuk non-struktural',
    level_jabatan  INT NOT NULL DEFAULT 4
                   COMMENT '1=Kepala Dinas, 2=Kabid/Sekretaris/Ka-UPT, 3=Kasi/Kasubbag, 4=Fungsional/Pelaksana',
    keterangan     TEXT,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- =========================================================================
-- 3. PEGAWAI
-- Perubahan:
--   status_kepegawaian : ditambah 'PPPK_Paruh_Waktu' sesuai data xlsx
--   status_aktif       : ditambah 'meninggal' sesuai data xlsx
-- =========================================================================
CREATE TABLE pegawai (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    nip                  VARCHAR(25)  NOT NULL UNIQUE COMMENT 'NIP 18 digit sesuai BKN',
    nip_lama             VARCHAR(20)  NULL,
    nama_lengkap         VARCHAR(150) NOT NULL,
    gelar_depan          VARCHAR(30)  NULL,
    gelar_belakang       VARCHAR(30)  NULL,
    jenis_kelamin        ENUM('L','P') NOT NULL DEFAULT 'L',
    tempat_lahir         VARCHAR(100) NULL,
    tanggal_lahir        DATE         NULL,
    agama                VARCHAR(30)  NULL,
    status_pernikahan    VARCHAR(30)  NULL,
    golongan_ruang       VARCHAR(10)  NULL COMMENT 'Contoh: III/a, IV/b (format tanpa strip, misal III/a bukan III-a)',
    tmt_golongan         DATE         NULL,
    pendidikan_terakhir  ENUM('SD','SLTP','SLTA','D-III','D-IV','S-1','S-2','S-3') NULL,
    prodi                VARCHAR(150) NULL COMMENT 'Program studi / bidang ilmu',
    tahun_lulus          YEAR         NULL,
    jabatan_id           INT          NULL,
    unit_kerja_id        INT          NULL,
    atasan_langsung_id   INT          NULL COMMENT 'Self-reference: pegawai atasan langsung',
    tmt_jabatan          DATE         NULL,
    status_kepegawaian   ENUM('PNS','CPNS','PPPK','PPPK_Paruh_Waktu','Honorer') NOT NULL DEFAULT 'PNS',
    no_hp                VARCHAR(20)  NULL,
    email                VARCHAR(150) NULL,
    alamat               TEXT         NULL,
    foto                 VARCHAR(255) NULL,
    status_aktif         ENUM('aktif','nonaktif','pensiun','mutasi','meninggal') NOT NULL DEFAULT 'aktif',
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pegawai_jabatan   FOREIGN KEY (jabatan_id)         REFERENCES jabatan(id)    ON DELETE SET NULL,
    CONSTRAINT fk_pegawai_unitkerja FOREIGN KEY (unit_kerja_id)      REFERENCES unit_kerja(id) ON DELETE SET NULL,
    CONSTRAINT fk_pegawai_atasan    FOREIGN KEY (atasan_langsung_id) REFERENCES pegawai(id)    ON DELETE SET NULL,
    INDEX idx_pegawai_unitkerja (unit_kerja_id),
    INDEX idx_pegawai_jabatan   (jabatan_id),
    INDEX idx_pegawai_atasan    (atasan_langsung_id),
    INDEX idx_pegawai_nip       (nip)
);

-- Lengkapi FK unit_kerja.kepala_unit_id sekarang pegawai sudah ada
ALTER TABLE unit_kerja
    ADD CONSTRAINT fk_unitkerja_kepala FOREIGN KEY (kepala_unit_id) REFERENCES pegawai(id) ON DELETE SET NULL;

-- =========================================================================
-- 4. USERS
-- Kredensial & role akses — terhubung 1:1 ke pegawai.
-- pegawai_id NULL diperbolehkan untuk akun Super Admin sistem non-pegawai.
-- =========================================================================
CREATE TABLE users (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    pegawai_id   INT          NULL UNIQUE,
    username     VARCHAR(50)  NOT NULL UNIQUE COMMENT 'Username login; admin/superadmin tidak terhubung ke pegawai',
    password     VARCHAR(255) NOT NULL    COMMENT 'Hash bcrypt (password_hash)',
    role         ENUM('super_admin','admin','pegawai') NOT NULL DEFAULT 'pegawai'
                 COMMENT 'super_admin=akses penuh, admin=akses operasional, pegawai=akses terbatas',
    is_registered TINYINT(1)  NOT NULL DEFAULT 1 COMMENT '0=pre-enrolled belum registrasi mandiri',
    last_login   DATETIME     NULL,
    status       ENUM('aktif','nonaktif','terkunci') NOT NULL DEFAULT 'aktif',
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_pegawai FOREIGN KEY (pegawai_id) REFERENCES pegawai(id) ON DELETE CASCADE
);

-- =========================================================================
-- 5. PERIODE_PENILAIAN
-- =========================================================================
CREATE TABLE periode_penilaian (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    kode_periode             VARCHAR(30)  NOT NULL UNIQUE,
    nama_periode             VARCHAR(150) NOT NULL,
    tahun                    YEAR         NOT NULL,
    jenis_periode            ENUM('tahunan','semester','triwulan','bulanan','lainnya')
                             NOT NULL DEFAULT 'tahunan',
    semester                 VARCHAR(10)  NOT NULL DEFAULT 'tahunan'
                             COMMENT 'Urutan sub-periode: tahunan, 1, 2, 3, 4, ...',
    target_penilaian         ENUM('semua','golongan_tertentu') NOT NULL DEFAULT 'semua'
                             COMMENT 'semua=seluruh pegawai dinilai; golongan_tertentu=hanya golongan tertentu yang dinilai',
    target_golongan          VARCHAR(100) NULL COMMENT 'Filter golongan target dinilai, misal III,IV atau JSON ["III","IV"]',
    tanggal_mulai            DATE         NOT NULL,
    tanggal_selesai          DATE         NOT NULL,
    tanggal_mulai_pengisian  DATETIME     NULL,
    tanggal_akhir_pengisian  DATETIME     NULL,
    status                   ENUM('draft','aktif','ditutup','diarsipkan') NOT NULL DEFAULT 'draft',
    keterangan               TEXT,
    created_by               INT          NULL,
    created_at               DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_periode_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_periode_status       (status),
    INDEX idx_periode_tahun_jenis  (tahun, jenis_periode)
);

-- =========================================================================
-- 6. KATEGORI_PENILAIAN
-- =========================================================================
CREATE TABLE kategori_penilaian (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    kode_kategori    VARCHAR(20)  NOT NULL UNIQUE,
    nama_kategori    VARCHAR(150) NOT NULL,
    deskripsi        TEXT,
    bobot_kategori   DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Persentase bobot kategori dari total 100%',
    urutan           INT DEFAULT 0,
    status           ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- =========================================================================
-- 7. INDIKATOR_PENILAIAN
-- =========================================================================
CREATE TABLE indikator_penilaian (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    kategori_id       INT          NOT NULL,
    kode_indikator    VARCHAR(20)  NOT NULL UNIQUE,
    nama_indikator    VARCHAR(200) NOT NULL,
    deskripsi         TEXT,
    bobot_indikator   DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    urutan            INT DEFAULT 0,
    status            ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_indikator_kategori FOREIGN KEY (kategori_id) REFERENCES kategori_penilaian(id) ON DELETE CASCADE,
    INDEX idx_indikator_kategori (kategori_id)
);

-- =========================================================================
-- 8. PERTANYAAN_PENILAIAN
-- =========================================================================
CREATE TABLE pertanyaan_penilaian (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    indikator_id      INT     NOT NULL,
    pertanyaan        TEXT    NOT NULL,
    jenis_pertanyaan  ENUM('skala','esai') NOT NULL DEFAULT 'skala',
    target_relasi     VARCHAR(100) NOT NULL DEFAULT 'atasan,rekan,bawahan,diri',
    bobot             DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    skor_min          INT     NOT NULL DEFAULT 1,
    skor_max          INT     NOT NULL DEFAULT 5,
    urutan            INT DEFAULT 0,
    status            ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pertanyaan_indikator FOREIGN KEY (indikator_id) REFERENCES indikator_penilaian(id) ON DELETE CASCADE,
    INDEX idx_pertanyaan_indikator (indikator_id)
);

-- =========================================================================
-- 9. MAPPING_PENILAI
-- =========================================================================
CREATE TABLE mapping_penilai (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    periode_id           INT NOT NULL,
    pegawai_dinilai_id   INT NOT NULL,
    pegawai_penilai_id   INT NOT NULL,
    jenis_relasi         ENUM('diri','atasan','rekan','bawahan') NOT NULL,
    bobot_relasi         DECIMAL(5,2) NULL COMMENT 'Override bobot khusus; NULL = pakai default dari konfigurasi_sistem',
    status               ENUM('belum_mulai','proses','selesai') NOT NULL DEFAULT 'belum_mulai',
    tanggal_mulai_isi    DATETIME NULL,
    tanggal_submit       DATETIME NULL,
    komentar_masukan     TEXT     NULL,
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mapping_periode  FOREIGN KEY (periode_id)         REFERENCES periode_penilaian(id) ON DELETE CASCADE,
    CONSTRAINT fk_mapping_dinilai  FOREIGN KEY (pegawai_dinilai_id) REFERENCES pegawai(id)           ON DELETE CASCADE,
    CONSTRAINT fk_mapping_penilai  FOREIGN KEY (pegawai_penilai_id) REFERENCES pegawai(id)           ON DELETE CASCADE,
    UNIQUE KEY unique_mapping (periode_id, pegawai_dinilai_id, pegawai_penilai_id, jenis_relasi),
    INDEX idx_mapping_periode_status (periode_id, status)
);

-- =========================================================================
-- 10. JAWABAN_PENILAIAN
-- =========================================================================
CREATE TABLE jawaban_penilaian (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    mapping_id      INT NOT NULL,
    pertanyaan_id   INT NOT NULL,
    skor            INT NOT NULL,
    komentar        TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_jawaban_mapping     FOREIGN KEY (mapping_id)    REFERENCES mapping_penilai(id)     ON DELETE CASCADE,
    CONSTRAINT fk_jawaban_pertanyaan  FOREIGN KEY (pertanyaan_id) REFERENCES pertanyaan_penilaian(id) ON DELETE CASCADE,
    UNIQUE KEY unique_jawaban (mapping_id, pertanyaan_id)
);

-- =========================================================================
-- 11. HASIL_PENILAIAN
-- =========================================================================
CREATE TABLE hasil_penilaian (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    periode_id       INT  NOT NULL,
    pegawai_id       INT  NOT NULL,
    nilai_diri       DECIMAL(5,2) NULL,
    nilai_atasan     DECIMAL(5,2) NULL,
    nilai_rekan      DECIMAL(5,2) NULL,
    nilai_bawahan    DECIMAL(5,2) NULL,
    nilai_akhir      DECIMAL(5,2) NULL COMMENT 'Hasil weighted-average sesuai bobot di konfigurasi_sistem',
    predikat         ENUM('Sangat Baik','Baik','Cukup','Kurang','Sangat Kurang') NULL,
    tanggal_hitung   DATETIME NULL,
    dihitung_oleh    INT      NULL,
    catatan          TEXT,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hasil_periode    FOREIGN KEY (periode_id)    REFERENCES periode_penilaian(id) ON DELETE CASCADE,
    CONSTRAINT fk_hasil_pegawai    FOREIGN KEY (pegawai_id)    REFERENCES pegawai(id)           ON DELETE CASCADE,
    CONSTRAINT fk_hasil_penghitung FOREIGN KEY (dihitung_oleh) REFERENCES users(id)             ON DELETE SET NULL,
    UNIQUE KEY unique_hasil (periode_id, pegawai_id),
    INDEX idx_hasil_periode (periode_id)
);

-- =========================================================================
-- 12. AUDIT_LOG
-- =========================================================================
CREATE TABLE audit_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT          NULL,
    modul         VARCHAR(50)  NOT NULL COMMENT 'Contoh: pegawai, periode_penilaian, mapping_penilai',
    aktivitas     VARCHAR(255) NOT NULL COMMENT 'Contoh: create, update, delete, login, submit_penilaian',
    data_sebelum  JSON NULL,
    data_sesudah  JSON NULL,
    ip_address    VARCHAR(45)  NULL,
    user_agent    VARCHAR(255) NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_modul   (modul),
    INDEX idx_audit_user    (user_id),
    INDEX idx_audit_created (created_at)
);

-- =========================================================================
-- 13. KONFIGURASI_SISTEM
-- =========================================================================
CREATE TABLE konfigurasi_sistem (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    config_key    VARCHAR(100) NOT NULL UNIQUE,
    config_value  TEXT,
    tipe_data     ENUM('string','integer','decimal','boolean','json') NOT NULL DEFAULT 'string',
    kategori      VARCHAR(50)  NOT NULL DEFAULT 'umum',
    keterangan    VARCHAR(255),
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

SET FOREIGN_KEY_CHECKS = 1;
