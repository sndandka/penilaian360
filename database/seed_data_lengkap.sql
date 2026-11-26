-- =========================================================================
-- SISTEM PENILAIAN 360 - SEED DATA LENGKAP (DATA MASTER & INSTRUMEN AWAL)
-- Dinas Pekerjaan Umum Bina Marga Provinsi Jawa Timur
-- =========================================================================
-- Dipisahkan dari: database/schema.sql
--
-- Prasyarat Import:
--   1. database/schema.sql             (skema database & struktur 13 tabel)
--   2. database/seed_data_lengkap.sql  (file ini — konfigurasi sistem,
--                                       64 unit kerja, 27 jabatan, 2 akun
--                                       admin default, 6 kategori, 6 indikator,
--                                       dan 40 butir pertanyaan penilaian)
--
-- Cara Import (CLI):
--   mysql -u root -p penilaian360 < database/seed_data_lengkap.sql
-- =========================================================================

USE penilaian360;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =========================================================================
-- SEED DATA — Konfigurasi Sistem
-- =========================================================================
INSERT INTO konfigurasi_sistem (config_key, config_value, tipe_data, kategori, keterangan) VALUES
('nama_aplikasi',               'Sistem Penilaian Kinerja 360',                                 'string',  'umum',      'Nama aplikasi yang tampil di header'),
('nama_instansi',               'Dinas Pekerjaan Umum Bina Marga Provinsi Jawa Timur',          'string',  'umum',      'Nama instansi'),
('logo_url',                    'https://tse1.mm.bing.net/th/id/OIP.bGFyA5TcKH8Kz28loGHBxAHaHa?r=0&rs=1&pid=ImgDetMain&o=7&rm=3', 'string',  'umum',      'URL logo instansi'),
('skala_penilaian_default',     '1-5',                                                          'string',  'penilaian', 'Skala default penilaian'),
('bobot_atasan',                '50',                                                           'decimal', 'bobot',     'Persentase bobot penilaian dari atasan langsung untuk Pejabat Struktural (50%)'),
('bobot_rekan',                 '15',                                                           'decimal', 'bobot',     'Persentase bobot penilaian dari rekan kerja untuk Pejabat Struktural (15%)'),
('bobot_bawahan',               '25',                                                           'decimal', 'bobot',     'Persentase bobot penilaian dari bawahan untuk Pejabat Struktural (25%)'),
('bobot_diri_sendiri',          '10',                                                           'decimal', 'bobot',     'Persentase bobot penilaian dari diri sendiri / self-assessment (10%)'),
('reminder_email',              '1',                                                            'boolean', 'notifikasi','Aktifkan reminder email (1/0)'),
('ambang_predikat_sangat_baik', '91',                                                           'decimal', 'predikat',  'Nilai akhir >= ini = Sangat Baik'),
('ambang_predikat_baik',        '76',                                                           'decimal', 'predikat',  'Nilai akhir >= ini = Baik'),
('ambang_predikat_cukup',       '61',                                                           'decimal', 'predikat',  'Nilai akhir >= ini = Cukup'),
('ambang_predikat_kurang',      '51',                                                           'decimal', 'predikat',  'Nilai akhir >= ini = Kurang, di bawah itu = Sangat Kurang'),
('bobot_pelaksana_atasan',      '50',                                                           'decimal', 'bobot',     'Persentase bobot penilaian dari atasan langsung untuk Pegawai Pelaksana/Fungsional (50%)'),
('bobot_pelaksana_rekan',       '40',                                                           'decimal', 'bobot',     'Persentase bobot penilaian dari rekan kerja untuk Pegawai Pelaksana/Fungsional (40%)'),
('bobot_pelaksana_diri_sendiri','10',                                                           'decimal', 'bobot',     'Persentase bobot penilaian dari diri sendiri untuk Pegawai Pelaksana/Fungsional (10%)');

-- =========================================================================
-- SEED DATA — Unit Kerja
-- Sumber: kolom UNIT KERJA ES II / ES III / ES IV pada xlsx pegawai
--
-- Tingkat 1 (ES II)  — id 1
-- Tingkat 2 (ES III) — id 2..18
-- Tingkat 3 (ES IV)  — id 19..47
-- =========================================================================

-- Tingkat 1: Dinas (ES II)
INSERT INTO unit_kerja (id, kode_unit, nama_unit, jenis_unit, parent_id, status) VALUES
(1, 'DIS-BM', 'Dinas Pekerjaan Umum Bina Marga', 'dinas', NULL, 'aktif');

-- Tingkat 2: Sekretariat, Bidang, UPT (ES III) — semua ber-parent Dinas (id=1)
INSERT INTO unit_kerja (id, kode_unit, nama_unit, jenis_unit, parent_id, status) VALUES
(2,  'SEKRE',      'Sekretariat',                                                              'sekretariat', 1, 'aktif'),
(3,  'BID-BT',     'Bidang Bina Teknik',                                                       'bidang',      1, 'aktif'),
(4,  'BID-PP',     'Bidang Pembangunan dan Peningkatan',                                       'bidang',      1, 'aktif'),
(5,  'BID-HAR',    'Bidang Pemeliharaan',                                                      'bidang',      1, 'aktif'),
(6,  'BID-DAL',    'Bidang Pengaturan dan Pengendalian',                                       'bidang',      1, 'aktif'),
(7,  'UPT-SBY',    'Unit Pelaksana Teknis Pengelolaan Jalan dan Jembatan Surabaya',            'upt',         1, 'aktif'),
(8,  'UPT-MJK',    'Unit Pelaksana Teknis Pengelolaan Jalan dan Jembatan Mojokerto',           'upt',         1, 'aktif'),
(9,  'UPT-BJN',    'Unit Pelaksana Teknis Pengelolaan Jalan dan Jembatan Bojonegoro',          'upt',         1, 'aktif'),
(10, 'UPT-MDN',    'Unit Pelaksana Teknis Pengelolaan Jalan dan Jembatan Madiun',              'upt',         1, 'aktif'),
(11, 'UPT-PCT',    'Unit Pelaksana Teknis Pengelolaan Jalan dan Jembatan Pacitan',             'upt',         1, 'aktif'),
(12, 'UPT-KDR',    'Unit Pelaksana Teknis Pengelolaan Jalan dan Jembatan Kediri',              'upt',         1, 'aktif'),
(13, 'UPT-MLG',    'Unit Pelaksana Teknis Pengelolaan Jalan dan Jembatan Malang',              'upt',         1, 'aktif'),
(14, 'UPT-PBG',    'Unit Pelaksana Teknis Pengelolaan Jalan dan Jembatan Probolinggo',         'upt',         1, 'aktif'),
(15, 'UPT-JBR',    'Unit Pelaksana Teknis Pengelolaan Jalan dan Jembatan Jember',              'upt',         1, 'aktif'),
(16, 'UPT-BWI',    'Unit Pelaksana Teknis Pengelolaan Jalan dan Jembatan Banyuwangi',          'upt',         1, 'aktif'),
(17, 'UPT-PMK',    'Unit Pelaksana Teknis Pengelolaan Jalan dan Jembatan Pamekasan',           'upt',         1, 'aktif'),
(18, 'UPT-LAB',    'Unit Pelaksana Teknis Laboratorium Pengujian',                            'upt_lab',     1, 'aktif');

-- Tingkat 3: ES IV: Seksi & Sub Bagian — parent = unit ES III masing-masing
INSERT INTO unit_kerja (id, kode_unit, nama_unit, jenis_unit, parent_id, status) VALUES
-- Sekretariat (parent=2)
(19, 'SEKRE-SUBAG',  'Subbagian Umum dan Kepegawaian',                             'subbagian',           2,  'aktif'),
-- Bidang Bina Teknik (parent=3)
(20, 'BID-BT-RJJ',   'Seksi Rekayasa Teknik Jalan dan Jembatan',                  'seksi',               3,  'aktif'),
(21, 'BID-BT-WAS',   'Seksi Pengawasan Jalan dan Jembatan',                       'seksi',               3,  'aktif'),
-- Bidang Pembangunan dan Peningkatan (parent=4)
(22, 'BID-PP-JLN',   'Seksi Pelaksanaan Pembangunan dan Peningkatan Jalan',        'seksi',               4,  'aktif'),
(23, 'BID-PP-JBT',   'Seksi Pelaksanaan Pembangunan dan Peningkatan Jembatan',     'seksi',               4,  'aktif'),
(24, 'BID-PP-KJF',   'Kelompok Jabatan Fungsional Bidang Pembangunan',             'kelompok_fungsional', 4,  'aktif'),
-- Bidang Pemeliharaan (parent=5)
(25, 'BID-HAR-JLN',  'Seksi Pemeliharaan Jalan',                                  'seksi',               5,  'aktif'),
(26, 'BID-HAR-JBT',  'Seksi Pemeliharaan Jembatan',                               'seksi',               5,  'aktif'),
-- Bidang Pengaturan dan Pengendalian (parent=6)
(27, 'BID-DAL-ATR',  'Seksi Pengaturan Jalan dan Jembatan',                       'seksi',               6,  'aktif'),
(28, 'BID-DAL-DAL',  'Seksi Pengendalian Jalan dan Jembatan',                     'seksi',               6,  'aktif'),
-- UPT Surabaya (parent=7)
(29, 'UPT-SBY-PP',   'Seksi Pembangunan dan Peningkatan Jalan dan Jembatan Surabaya',      'seksi',               7,  'aktif'),
(30, 'UPT-SBY-HAR',  'Seksi Pemeliharaan Jalan dan Jembatan Surabaya',                    'seksi',               7,  'aktif'),
(31, 'UPT-SBY-TU',   'Sub Bagian Tata Usaha Surabaya',                                     'sub_bagian_tata_usaha',7, 'aktif'),
-- UPT Mojokerto (parent=8)
(32, 'UPT-MJK-PP',   'Seksi Pembangunan dan Peningkatan Jalan dan Jembatan Mojokerto',      'seksi',               8,  'aktif'),
(33, 'UPT-MJK-HAR',  'Seksi Pemeliharaan Jalan dan Jembatan Mojokerto',                    'seksi',               8,  'aktif'),
(34, 'UPT-MJK-TU',   'Sub Bagian Tata Usaha Mojokerto',                                     'sub_bagian_tata_usaha',8, 'aktif'),
-- UPT Bojonegoro (parent=9)
(35, 'UPT-BJN-PP',   'Seksi Pembangunan dan Peningkatan Jalan dan Jembatan Bojonegoro',      'seksi',               9,  'aktif'),
(36, 'UPT-BJN-HAR',  'Seksi Pemeliharaan Jalan dan Jembatan Bojonegoro',                    'seksi',               9,  'aktif'),
(37, 'UPT-BJN-TU',   'Sub Bagian Tata Usaha Bojonegoro',                                     'sub_bagian_tata_usaha',9, 'aktif'),
-- UPT Madiun (parent=10)
(38, 'UPT-MDN-PP',   'Seksi Pembangunan dan Peningkatan Jalan dan Jembatan Madiun',      'seksi',               10, 'aktif'),
(39, 'UPT-MDN-HAR',  'Seksi Pemeliharaan Jalan dan Jembatan Madiun',                    'seksi',               10, 'aktif'),
(40, 'UPT-MDN-TU',   'Sub Bagian Tata Usaha Madiun',                                     'sub_bagian_tata_usaha',10,'aktif'),
-- UPT Pacitan (parent=11)
(41, 'UPT-PCT-PP',   'Seksi Pembangunan dan Peningkatan Jalan dan Jembatan Pacitan',      'seksi',               11, 'aktif'),
(42, 'UPT-PCT-HAR',  'Seksi Pemeliharaan Jalan dan Jembatan Pacitan',                    'seksi',               11, 'aktif'),
(43, 'UPT-PCT-TU',   'Sub Bagian Tata Usaha Pacitan',                                     'sub_bagian_tata_usaha',11,'aktif'),
-- UPT Kediri (parent=12)
(44, 'UPT-KDR-PP',   'Seksi Pembangunan dan Peningkatan Jalan dan Jembatan Kediri',      'seksi',               12, 'aktif'),
(45, 'UPT-KDR-HAR',  'Seksi Pemeliharaan Jalan dan Jembatan Kediri',                    'seksi',               12, 'aktif'),
(46, 'UPT-KDR-TU',   'Sub Bagian Tata Usaha Kediri',                                     'sub_bagian_tata_usaha',12,'aktif'),
-- UPT Malang (parent=13)
(47, 'UPT-MLG-PP',   'Seksi Pembangunan dan Peningkatan Jalan dan Jembatan Malang',      'seksi',               13, 'aktif'),
(48, 'UPT-MLG-HAR',  'Seksi Pemeliharaan Jalan dan Jembatan Malang',                    'seksi',               13, 'aktif'),
(49, 'UPT-MLG-TU',   'Sub Bagian Tata Usaha Malang',                                     'sub_bagian_tata_usaha',13,'aktif'),
-- UPT Probolinggo (parent=14)
(50, 'UPT-PBG-PP',   'Seksi Pembangunan dan Peningkatan Jalan dan Jembatan Probolinggo',      'seksi',               14, 'aktif'),
(51, 'UPT-PBG-HAR',  'Seksi Pemeliharaan Jalan dan Jembatan Probolinggo',                    'seksi',               14, 'aktif'),
(52, 'UPT-PBG-TU',   'Sub Bagian Tata Usaha Probolinggo',                                     'sub_bagian_tata_usaha',14,'aktif'),
-- UPT Jember (parent=15)
(53, 'UPT-JBR-PP',   'Seksi Pembangunan dan Peningkatan Jalan dan Jembatan Jember',      'seksi',               15, 'aktif'),
(54, 'UPT-JBR-HAR',  'Seksi Pemeliharaan Jalan dan Jembatan Jember',                    'seksi',               15, 'aktif'),
(55, 'UPT-JBR-TU',   'Sub Bagian Tata Usaha Jember',                                     'sub_bagian_tata_usaha',15,'aktif'),
-- UPT Banyuwangi (parent=16)
(56, 'UPT-BWI-PP',   'Seksi Pembangunan dan Peningkatan Jalan dan Jembatan Banyuwangi',      'seksi',               16, 'aktif'),
(57, 'UPT-BWI-HAR',  'Seksi Pemeliharaan Jalan dan Jembatan Banyuwangi',                    'seksi',               16, 'aktif'),
(58, 'UPT-BWI-TU',   'Sub Bagian Tata Usaha Banyuwangi',                                     'sub_bagian_tata_usaha',16,'aktif'),
-- UPT Pamekasan (parent=17)
(59, 'UPT-PMK-PP',   'Seksi Pembangunan dan Peningkatan Jalan dan Jembatan Pamekasan',      'seksi',               17, 'aktif'),
(60, 'UPT-PMK-HAR',  'Seksi Pemeliharaan Jalan dan Jembatan Pamekasan',                    'seksi',               17, 'aktif'),
(61, 'UPT-PMK-TU',   'Sub Bagian Tata Usaha Pamekasan',                                     'sub_bagian_tata_usaha',17,'aktif'),
-- UPT Laboratorium (parent=18)
(62, 'UPT-LAB-MM',   'Seksi Manajemen Mutu',                                      'seksi',               18, 'aktif'),
(63, 'UPT-LAB-PJ',   'Seksi Pelayanan dan Pengujian',                             'seksi',               18, 'aktif'),
(64, 'UPT-LAB-TU',   'Sub Bagian Tata Usaha Laboratorium',                        'sub_bagian_tata_usaha',18, 'aktif');

-- =========================================================================
-- SEED DATA — Jabatan
-- Sumber: kolom JENIS JABATAN, ESELON, NAMA JABATAN pada xlsx pegawai
-- =========================================================================
INSERT INTO jabatan (kode_jabatan, nama_jabatan, jenis_jabatan, eselon, level_jabatan, keterangan) VALUES
-- ---- Jabatan Struktural ----
('KADIS',     'Kepala Dinas',      'struktural', 'II.a',  1, 'Pimpinan Tinggi Pratama'),
('KABID',     'Kepala Bidang',     'struktural', 'III.a', 2, 'Administrator'),
('SEKRETARIS','Sekretaris',        'struktural', 'III.a', 2, 'Administrator'),
('KA-UPT',    'Kepala UPT',        'struktural', 'III.b', 2, 'Administrator'),
('KASI',      'Kepala Seksi',      'struktural', 'IV.a',  3, 'Pengawas'),
('KASUBBAG',  'Kepala Sub Bagian', 'struktural', 'IV.a',  3, 'Pengawas'),
-- ---- Jabatan Fungsional ----
('FUN-APIP-AHM',  'Analis Pembiayaan Infrastruktur Pekerjaan Umum Dan Perumahan Keahlian Ahli Madya (IV/a)',    'fungsional', NULL, 4, NULL),
('FUN-ASDMA-AHP', 'Analis Sumber Daya Manusia Aparatur Keahlian Ahli Pertama (III/a)',                          'fungsional', NULL, 4, NULL),
('FUN-ARS-AHP',   'Arsiparis Keahlian Ahli Pertama (III/a)',                                                    'fungsional', NULL, 4, NULL),
('FUN-PKJ-AHM',   'Penata Kelola Jalan dan Jembatan Keahlian Ahli Muda (III/c)',                                'fungsional', NULL, 4, NULL),
('FUN-PKJ-AHP-A', 'Penata Kelola Jalan dan Jembatan Keahlian Ahli Pertama (III/a)',                             'fungsional', NULL, 4, NULL),
('FUN-PKJ-AHP-B', 'Penata Kelola Jalan dan Jembatan Keahlian Ahli Pertama (III/b)',                             'fungsional', NULL, 4, NULL),
('FUN-PKSTI',     'Penata Kelola Sistem dan Teknologi Informasi',                                               'fungsional', NULL, 4, NULL),
('FUN-PLB-TR',    'Penata Laksana Barang Keterampilan Terampil (II/c)',                                         'fungsional', NULL, 4, NULL),
('FUN-PLJ-TR',    'Penata Laksana Jalan dan Jembatan Keterampilan Terampil (II/c)',                             'fungsional', NULL, 4, NULL),
('FUN-PERC-AHP',  'Perencana Keahlian Ahli Pertama (III/a)',                                                    'fungsional', NULL, 4, NULL),
('FUN-PRANKOM-TR','Pranata Komputer Keterampilan Terampil (II/c)',                                              'fungsional', NULL, 4, NULL),
('FUN-TJJ-AHP',   'Teknik Jalan dan Jembatan Keahlian Ahli Pertama (III/a)',                                    'fungsional', NULL, 4, NULL),
-- ---- Jabatan Pelaksana ----
('PEL-FASPED',    'Fasilitator Pemerintahan',        'pelaksana', NULL, 4, NULL),
('PEL-OPAB',      'Operator Alat Berat',             'pelaksana', NULL, 4, NULL),
('PEL-OPLO',      'Operator Layanan Operasional',    'pelaksana', NULL, 4, NULL),
('PEL-PENLO',     'Penata Layanan Operasional',      'pelaksana', NULL, 4, NULL),
('PEL-PENLAI',    'Penelaah Teknis Kebijakan',       'pelaksana', NULL, 4, NULL),
('PEL-PENGPER',   'Pengadministrasi Perkantoran',    'pelaksana', NULL, 4, NULL),
('PEL-PENGLO',    'Pengelola Layanan Operasional',   'pelaksana', NULL, 4, NULL),
('PEL-PENGUO',    'Pengelola Umum Operasional',      'pelaksana', NULL, 4, NULL),
('PEL-PNGDI',     'Pengolah Data dan Informasi',     'pelaksana', NULL, 4, NULL);

-- =========================================================================
-- SEED DATA — Akun Default Sistem (Super Admin & Admin Kepegawaian)
-- Password default: superadmin = superbmprovjatim1 | admin = adminbmprovjatim
-- Login menggunakan USERNAME (bukan NIP)
-- Akun pegawai diisi melalui Import Excel Pegawai
-- =========================================================================
INSERT INTO users (pegawai_id, username, password, role, is_registered, status) VALUES
(NULL, 'superadmin', '$2y$10$6IjMMmcUFTdD0diHKCriV.owkNEKX5j1eFs4WXAW3iatmRmKWVQ8G', 'super_admin', 1, 'aktif'),
(NULL, 'admin',      '$2y$10$53SIMtY2sesZyWXcGl4lrulYAb0IhUUZgPVVp7vlkklqpYq9Vy0je', 'admin',       1, 'aktif');

-- =========================================================================
-- SEED DATA — Kategori, Indikator, Pertanyaan Penilaian (6 Aspek Inti PNS PermenPANRB)
-- Paket 1: 20 Butir (target_relasi = 'bawahan') untuk menilai Atasan
-- Paket 2: 20 Butir (target_relasi = 'atasan,rekan,diri') untuk menilai Rekan, Bawahan & Diri Sendiri
-- =========================================================================
INSERT INTO kategori_penilaian (id, kode_kategori, nama_kategori, deskripsi, bobot_kategori, urutan) VALUES
(1, 'OP', 'Orientasi Pelayanan', 'Sikap dan perilaku kerja PNS dalam memberikan pelayanan terbaik kepada yang dilayani antara lain meliputi masyarakat, atasan, rekan sekerja, unit kerja terkait, dan/atau instansi lain.', 20.00, 1),
(2, 'INT', 'Integritas', 'Kemampuan untuk bertindak sesuai dengan nilai, norma dan etika dalam organisasi.', 20.00, 2),
(3, 'KOM', 'Komitmen', 'Kemampuan dan kemauan untuk menyelaraskan sikap dan tindakan PNS untuk mewujudkan tujuan organisasi dengan mengutamakan kepentingan dinas daripada kepentingan diri sendiri, seseorang dan/atau golongan.', 20.00, 3),
(4, 'DIS', 'Disiplin', 'Kesanggupan PNS untuk menaati kewajiban dan menghindari larangan yang ditentukan dalam peraturan perundang-undangan dan/atau peraturan kedinasan yang apabila tidak ditaati atau dilanggar akan dijatuhi hukuman disiplin.', 20.00, 4),
(5, 'KJS', 'Kerjasama', 'Kemauan dan kemampuan PNS untuk bekerjasama dengan teman sekerja, atasan, bawahan dalam unit kerjanya, serta instansi lain dalam menyelesaikan suatu tugas dan tanggung jawab yang ditentukan sehingga mencapai daya guna dan hasil guna yang sebesar-besarnya.', 20.00, 5),
(6, 'KEP', 'Kepemimpinan', 'Kemampuan dan kemauan PNS untuk memotivasi dan mempengaruhi bawahan atau orang lain yang berkaitan dengan bidang tugasnya demi tercapainya tujuan organisasi (khusus bagi pejabat struktural/pemimpin).', 25.00, 6);

INSERT INTO indikator_penilaian (id, kategori_id, kode_indikator, nama_indikator, deskripsi, bobot_indikator, urutan) VALUES
(1, 1, 'IND-OP', 'Orientasi Pelayanan', 'Pemberian pelayanan prima kepada masyarakat, atasan, rekan sekerja, dan unit terkait.', 100.00, 1),
(2, 2, 'IND-INT', 'Integritas', 'Kepatuhan pada etika, norma, kejujuran, dan nilai-nilai organisasi.', 100.00, 2),
(3, 3, 'IND-KOM', 'Komitmen', 'Penyelarasan sikap demi tujuan organisasi dan loyalitas kedinasan.', 100.00, 3),
(4, 4, 'IND-DIS', 'Disiplin', 'Ketaatan pada peraturan, prosedur kedinasan, dan ketepatan target kerja.', 100.00, 4),
(5, 5, 'IND-KJS', 'Kerjasama', 'Kemampuan kolaborasi tim, komunikasi, dan koordinasi lintas unit.', 100.00, 5),
(6, 6, 'IND-KEP', 'Kepemimpinan', 'Kemampuan dan kemauan PNS untuk memotivasi dan mempengaruhi bawahan atau orang lain yang berkaitan dengan bidang tugasnya demi tercapainya tujuan organisasi (khusus bagi pejabat struktural/pemimpin).', 100.00, 6);

INSERT INTO pertanyaan_penilaian (id, indikator_id, pertanyaan, jenis_pertanyaan, target_relasi, bobot, skor_min, skor_max, urutan) VALUES
-- === PAKET 1: Kuesioner Bawahan Menilai Atasan (target_relasi = 'bawahan', ID 1 - 20) ===
(1, 1, 'Atasan saya memahami kebutuhan dan kepentingan pemangku kepentingan serta mengarahkan pegawai untuk memberikan pelayanan sesuai kebutuhan tersebut.', 'skala', 'bawahan', 1.00, 1, 5, 1),
(2, 1, 'Atasan saya membangun hubungan kerja yang produktif melalui komunikasi yang baik, responsif, dan profesional dengan pegawai maupun pemangku kepentingan.', 'skala', 'bawahan', 1.00, 1, 5, 2),
(3, 1, 'Atasan saya berinisiatif mencari solusi dan melakukan perbaikan untuk meningkatkan kualitas serta kecepatan pelayanan.', 'skala', 'bawahan', 1.00, 1, 5, 3),
(4, 2, 'Atasan saya menunjukkan perilaku yang sesuai dengan norma, kode etik, standar profesional, dan nilai-nilai organisasi.', 'skala', 'bawahan', 1.00, 1, 5, 1),
(5, 2, 'Atasan saya menyampaikan informasi dan arahan pekerjaan secara jujur, objektif, akurat, dan dapat dipercaya.', 'skala', 'bawahan', 1.00, 1, 5, 2),
(6, 2, 'Atasan saya bertanggung jawab atas keputusan dan hasil pekerjaan serta memberikan contoh dalam memenuhi standar kualitas dan ketentuan yang berlaku.', 'skala', 'bawahan', 1.00, 1, 5, 3),
(7, 3, 'Atasan saya mengutamakan kepentingan organisasi dan kedinasan dibandingkan kepentingan pribadi atau golongan dalam menjalankan tugas.', 'skala', 'bawahan', 1.00, 1, 5, 1),
(8, 3, 'Atasan saya menunjukkan loyalitas dengan menjaga nama baik organisasi, mendukung kebijakan yang berlaku, dan menerapkan nilai-nilai organisasi.', 'skala', 'bawahan', 1.00, 1, 5, 2),
(9, 3, 'Atasan saya bersedia menerima dan melaksanakan tanggung jawab baru demi mendukung pencapaian tujuan organisasi.', 'skala', 'bawahan', 1.00, 1, 5, 3),
(10, 4, 'Atasan saya memberikan contoh dalam mematuhi kebijakan, peraturan, prosedur, dan ketentuan yang berlaku di lingkungan kerja.', 'skala', 'bawahan', 1.00, 1, 5, 1),
(11, 4, 'Atasan saya mampu mengelola dan menyelesaikan pekerjaan sesuai target, standar, serta batas waktu yang telah ditentukan.', 'skala', 'bawahan', 1.00, 1, 5, 2),
(12, 4, 'Atasan saya secara konsisten mengevaluasi dan memperbaiki metode kerja untuk meningkatkan produktivitas dan kualitas hasil pekerjaan.', 'skala', 'bawahan', 1.00, 1, 5, 3),
(13, 5, 'Atasan saya mampu mengoordinasikan pembagian tugas dan tanggung jawab pegawai untuk mencapai tujuan tim.', 'skala', 'bawahan', 1.00, 1, 5, 1),
(14, 5, 'Atasan saya menghargai pendapat, mendengarkan saran, dan terbuka terhadap masukan dari pegawai dalam menyelesaikan permasalahan pekerjaan.', 'skala', 'bawahan', 1.00, 1, 5, 2),
(15, 5, 'Atasan saya mampu membangun kerja sama lintas unit serta mendorong penerapan metode dan solusi baru dalam menghadapi perubahan.', 'skala', 'bawahan', 1.00, 1, 5, 3),
(16, 6, 'Atasan saya mampu mengambil keputusan berdasarkan analisis yang mendalam, mempertimbangkan risiko, dan berpedoman pada ketentuan yang berlaku.', 'skala', 'bawahan', 1.00, 1, 5, 1),
(17, 6, 'Atasan saya mampu mengarahkan, membimbing, dan mendorong pegawai untuk beradaptasi serta melaksanakan perubahan dalam lingkungan kerja.', 'skala', 'bawahan', 1.00, 1, 5, 2),
(18, 6, 'Atasan saya mampu membagi pekerjaan secara jelas, menetapkan prioritas, serta menentukan target dan batas waktu penyelesaian tugas.', 'skala', 'bawahan', 1.00, 1, 5, 3),
(19, 6, 'Atasan saya berinisiatif melakukan inovasi dan perbaikan metode kerja serta menetapkan target yang menantang namun realistis untuk meningkatkan kinerja unit kerja.', 'skala', 'bawahan', 1.00, 1, 5, 4),
(20, 6, 'Atasan saya mampu membina hubungan kerja yang positif, bersikap ramah dan bijaksana, serta mengembangkan potensi pegawai untuk mencapai tujuan organisasi.', 'skala', 'bawahan', 1.00, 1, 5, 5),
-- === PAKET 2: Kuesioner untuk Rekan, Bawahan & Diri Sendiri (target_relasi = 'atasan,rekan,diri', ID 21 - 40) ===
(21, 1, 'Memahami kebutuhan dan kepentingan pemangku kepentingan serta berupaya memberikan pelayanan sesuai dengan kebutuhan tersebut.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 4),
(22, 1, 'Mampu membangun komunikasi dan hubungan kerja yang produktif dengan rekan kerja maupun pihak-pihak yang berkepentingan.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 5),
(23, 1, 'Memberikan pelayanan secara responsif, profesional, dan tepat waktu dalam melaksanakan tugas.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 6),
(24, 1, 'Berupaya mencari solusi dan melakukan perbaikan untuk meningkatkan kualitas pelayanan dan hasil pekerjaan.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 7),
(25, 2, 'Melaksanakan pekerjaan sesuai dengan norma, kode etik, dan nilai-nilai yang berlaku dalam organisasi.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 4),
(26, 2, 'Menyampaikan informasi dan laporan pekerjaan secara jujur, objektif, dan dapat dipercaya.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 5),
(27, 2, 'Bertanggung jawab atas hasil pekerjaan serta bersedia mengakui kesalahan yang terjadi dalam pelaksanaan tugas.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 6),
(28, 2, 'Melaksanakan pekerjaan secara profesional sesuai standar yang ditetapkan tanpa mengutamakan kepentingan pribadi.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 7),
(29, 3, 'Mengutamakan kepentingan organisasi dan kedinasan dibandingkan kepentingan pribadi atau golongan.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 4),
(30, 3, 'Menunjukkan loyalitas terhadap organisasi dengan menjaga nama baik serta mendukung pencapaian tujuan organisasi.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 5),
(31, 3, 'Bersedia menerima dan melaksanakan tugas serta tanggung jawab baru sesuai kebutuhan organisasi.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 6),
(32, 3, 'Menerapkan nilai-nilai Pancasila, peraturan pemerintah, dan nilai organisasi dalam menjalankan tugas sebagai aparatur negara.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 7),
(33, 4, 'Mematuhi kebijakan, peraturan, prosedur, dan ketentuan yang berlaku dalam lingkungan kerja.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 4),
(34, 4, 'Menyelesaikan pekerjaan sesuai dengan target, standar, dan batas waktu yang telah ditentukan.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 5),
(35, 4, 'Mampu mengelola dan menyelesaikan tugas secara mandiri tanpa harus selalu mendapatkan pengawasan dari atasan.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 6),
(36, 4, 'Secara konsisten melakukan evaluasi dan perbaikan terhadap proses kerja untuk meningkatkan produktivitas.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 7),
(37, 5, 'Melaksanakan tugas dan tanggung jawab sesuai peran yang diberikan dalam tim kerja.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 4),
(38, 5, 'Menghargai pendapat, mendengarkan saran, dan menerima masukan dari rekan kerja dalam menyelesaikan pekerjaan.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 5),
(39, 5, 'Berpartisipasi aktif dalam menyelesaikan permasalahan dan mencari solusi bersama dalam tim kerja.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 6),
(40, 5, 'Terbuka terhadap metode, pendekatan, dan inovasi baru serta mampu bekerja sama dengan unit kerja lain untuk mencapai tujuan organisasi.', 'skala', 'atasan,rekan,diri', 1.00, 1, 5, 7);

SET FOREIGN_KEY_CHECKS = 1;

