-- MySQL dump 10.13  Distrib 8.4.11, for Linux (x86_64)
--
-- Host: localhost    Database: mitra_kinerja
-- ------------------------------------------------------
-- Server version	8.4.11-0ubuntu0.26.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mitra_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `aksi` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `detail` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `mitra_id` (`mitra_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`mitra_id`) REFERENCES `mitra_kinerja` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2146 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `baseline_elemen`
--

DROP TABLE IF EXISTS `baseline_elemen`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `baseline_elemen` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mitra_id` int NOT NULL,
  `nomor_elemen` int NOT NULL,
  `kelompok` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_elemen` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `yang_diperiksa` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `sumber_bukti_minimum` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('TERVERIFIKASI','BELUM TERVERIFIKASI','BELUM TERSEDIA','TIDAK RELEVAN','BELUM DIISI') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BELUM DIISI',
  `fakta_pemeriksaan` text COLLATE utf8mb4_unicode_ci,
  `link_sumber_bukti` text COLLATE utf8mb4_unicode_ci,
  `catatan` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mitra_elemen` (`mitra_id`,`nomor_elemen`),
  CONSTRAINT `baseline_elemen_ibfk_1` FOREIGN KEY (`mitra_id`) REFERENCES `mitra_kinerja` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1465 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `early_warning`
--

DROP TABLE IF EXISTS `early_warning`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `early_warning` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mitra_id` int NOT NULL,
  `dimensi` enum('Masa berlaku','Aktivitas/tenggat','Data/eviden','PIC') COLLATE utf8mb4_unicode_ci NOT NULL,
  `kondisi` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('E0','E1','E2','E3','V0') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'V0',
  `fakta_bukti` text COLLATE utf8mb4_unicode_ci,
  `tindakan` text COLLATE utf8mb4_unicode_ci,
  `pic` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tenggat` date DEFAULT NULL,
  `progres` enum('BELUM MULAI','DALAM PROSES','SELESAI') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BELUM MULAI',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mitra_dimensi` (`mitra_id`,`dimensi`),
  CONSTRAINT `early_warning_ibfk_1` FOREIGN KEY (`mitra_id`) REFERENCES `mitra_kinerja` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=249 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `indikator_skor`
--

DROP TABLE IF EXISTS `indikator_skor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `indikator_skor` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mitra_id` int NOT NULL,
  `kode_indikator` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deskripsi` text COLLATE utf8mb4_unicode_ci,
  `bobot` tinyint NOT NULL,
  `referensi_baseline` text COLLATE utf8mb4_unicode_ci,
  `kondisi_baseline` text COLLATE utf8mb4_unicode_ci,
  `kondisi_saat_ini` text COLLATE utf8mb4_unicode_ci,
  `status_pemeriksaan` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BELUM DITELAAH',
  `temuan_bukti` text COLLATE utf8mb4_unicode_ci,
  `skor` tinyint DEFAULT NULL,
  `alasan_skor` text COLLATE utf8mb4_unicode_ci,
  `catatan_tindak_lanjut` text COLLATE utf8mb4_unicode_ci,
  `nilai` decimal(6,2) DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mitra_indikator` (`mitra_id`,`kode_indikator`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `indikator_skor_ibfk_1` FOREIGN KEY (`mitra_id`) REFERENCES `mitra_kinerja` (`id`) ON DELETE CASCADE,
  CONSTRAINT `indikator_skor_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_skor_range` CHECK (((`skor` is null) or (`skor` between 0 and 4)))
) ENGINE=InnoDB AUTO_INCREMENT=184 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `intervensi_pimpinan`
--

DROP TABLE IF EXISTS `intervensi_pimpinan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `intervensi_pimpinan` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mitra_id` int NOT NULL,
  `no_pemicu` tinyint NOT NULL,
  `pemicu_teks` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jawaban` enum('BELUM DIPASTIKAN','TIDAK','YA') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BELUM DIPASTIKAN',
  `bukti_alasan` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mitra_pemicu` (`mitra_id`,`no_pemicu`),
  CONSTRAINT `intervensi_pimpinan_ibfk_1` FOREIGN KEY (`mitra_id`) REFERENCES `mitra_kinerja` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=301 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `intervensi_usulan`
--

DROP TABLE IF EXISTS `intervensi_usulan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `intervensi_usulan` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mitra_id` int NOT NULL,
  `upaya_dilakukan` text COLLATE utf8mb4_unicode_ci,
  `uraian_kendala` text COLLATE utf8mb4_unicode_ci,
  `keputusan_diminta` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mitra_id` (`mitra_id`),
  CONSTRAINT `intervensi_usulan_ibfk_1` FOREIGN KEY (`mitra_id`) REFERENCES `mitra_kinerja` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mitra_kinerja`
--

DROP TABLE IF EXISTS `mitra_kinerja`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mitra_kinerja` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kode` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nomor_naskah` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `portofolio` enum('Pilot Utama','Cadangan') COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_mitra` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `judul` text COLLATE utf8mb4_unicode_ci,
  `jenis` enum('PKS','MoU') COLLATE utf8mb4_unicode_ci NOT NULL,
  `bidang` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'AHU',
  `pks_induk_id` int DEFAULT NULL,
  `tanggal_mulai` date DEFAULT NULL,
  `tanggal_berakhir` date DEFAULT NULL,
  `status_tanggal` enum('TERVERIFIKASI','BELUM TERVERIFIKASI') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BELUM TERVERIFIKASI',
  `evaluasi_per_tahun` int NOT NULL DEFAULT '4',
  `baseline_status` enum('BELUM DIISI','DALAM PROSES','TERVERIFIKASI / DIKUNCI') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BELUM DIISI',
  `baseline_locked_at` datetime DEFAULT NULL,
  `baseline_locked_by` int DEFAULT NULL,
  `baseline_pemeriksa` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `baseline_catatan_ringkasan` text COLLATE utf8mb4_unicode_ci,
  `cutoff_date` date DEFAULT NULL,
  `sumber_baseline` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pic_internal` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pic_internal_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pic_mitra` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pic_mitra_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pic_focal_point` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_naskah` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_kerjasama` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pemeriksa_id` int DEFAULT NULL,
  `tanggal_review` date DEFAULT NULL,
  `status_scorecard` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BELUM DINILAI',
  `posisi_portofolio` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BELUM DAPAT DITENTUKAN',
  `rekomendasi` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kode` (`kode`),
  KEY `pemeriksa_id` (`pemeriksa_id`),
  KEY `fk_mitra_induk` (`pks_induk_id`),
  CONSTRAINT `fk_mitra_induk` FOREIGN KEY (`pks_induk_id`) REFERENCES `mitra_kinerja` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mitra_kinerja_ibfk_1` FOREIGN KEY (`pemeriksa_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pra_pks`
--

DROP TABLE IF EXISTS `pra_pks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pra_pks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nomor_usulan` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipe_kerjasama` enum('Dalam Negeri','Luar Negeri') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Dalam Negeri',
  `jenis_naskah` enum('MoU','PKS','Lainnya') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PKS',
  `unit_pemrakarsa` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `penanggung_jawab_usulan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `calon_mitra` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `judul_rencana` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `tujuan_singkat` text COLLATE utf8mb4_unicode_ci,
  `ruang_lingkup` text COLLATE utf8mb4_unicode_ci,
  `penerima_manfaat` text COLLATE utf8mb4_unicode_ci,
  `perkiraan_mulai` date DEFAULT NULL,
  `perkiraan_selesai` date DEFAULT NULL,
  `file_usulan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `k1_kesesuaian_strategis` enum('YA','TIDAK') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'YA',
  `k2_kebutuhan_daya_ungkit` enum('YA','TIDAK') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'YA',
  `k3_kelayakan_mitra` enum('YA','TIDAK') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'YA',
  `k4_kesiapan_sumber_daya` enum('YA','TIDAK') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'YA',
  `k5_risiko_keberlanjutan` enum('YA','TIDAK') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'YA',
  `pertanyaan_uji` longtext COLLATE utf8mb4_unicode_ci,
  `trigger_khusus` longtext COLLATE utf8mb4_unicode_ci,
  `catatan_verifikasi` text COLLATE utf8mb4_unicode_ci,
  `gap_penyempurnaan` text COLLATE utf8mb4_unicode_ci,
  `unit_review_tambahan` text COLLATE utf8mb4_unicode_ci,
  `batas_waktu_penyempurnaan` date DEFAULT NULL,
  `status_rekomendasi` enum('Layak','Perlu Penyempurnaan','Tidak Prioritas / Tidak Layak') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Layak',
  `status_persetujuan` enum('Draft','Menunggu Persetujuan Pimpinan','Disetujui Pimpinan','Dikembalikan untuk Revisi','Ditolak Pimpinan') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `catatan_pimpinan` text COLLATE utf8mb4_unicode_ci,
  `tanggal_persetujuan` date DEFAULT NULL,
  `pimpinan_id` int DEFAULT NULL,
  `is_promoted_to_pks` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nomor_usulan` (`nomor_usulan`),
  KEY `pimpinan_id` (`pimpinan_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `pra_pks_ibfk_1` FOREIGN KEY (`pimpinan_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pra_pks_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rencana_kerja`
--

DROP TABLE IF EXISTS `rencana_kerja`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rencana_kerja` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mitra_id` int NOT NULL,
  `judul_rencana` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ruang_lingkup` text COLLATE utf8mb4_unicode_ci,
  `maksud_tujuan` text COLLATE utf8mb4_unicode_ci,
  `tanggal_mulai` date NOT NULL,
  `tanggal_selesai` date NOT NULL,
  `status` enum('Draft','Proses Persetujuan','Disetujui','Selesai') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Disetujui',
  `alasan_persetujuan` text COLLATE utf8mb4_unicode_ci,
  `draft_naskah` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `mitra_id` (`mitra_id`),
  CONSTRAINT `rencana_kerja_ibfk_1` FOREIGN KEY (`mitra_id`) REFERENCES `mitra_kinerja` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `siklus_monev`
--

DROP TABLE IF EXISTS `siklus_monev`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `siklus_monev` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mitra_id` int NOT NULL,
  `rencana_kerja_id` int DEFAULT NULL,
  `siklus_ke` int NOT NULL,
  `nama_siklus` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tanggal_target_evaluasi` date NOT NULL,
  `tanggal_realisasi_evaluasi` date DEFAULT NULL,
  `status_siklus` enum('Menunggu','Perlu Penilaian Segera','Sedang Dinilai','Selesai') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Menunggu',
  `catatan_monev` text COLLATE utf8mb4_unicode_ci,
  `nilai_siklus` decimal(6,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mitra_siklus` (`mitra_id`,`siklus_ke`),
  KEY `mitra_id` (`mitra_id`),
  KEY `rencana_kerja_id` (`rencana_kerja_id`),
  CONSTRAINT `siklus_monev_ibfk_1` FOREIGN KEY (`mitra_id`) REFERENCES `mitra_kinerja` (`id`) ON DELETE CASCADE,
  CONSTRAINT `siklus_monev_ibfk_2` FOREIGN KEY (`rencana_kerja_id`) REFERENCES `rencana_kerja` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=384 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tindak_lanjut`
--

DROP TABLE IF EXISTS `tindak_lanjut`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tindak_lanjut` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mitra_id` int NOT NULL,
  `tindakan` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenggat` date DEFAULT NULL,
  `status` enum('Belum','Proses','Selesai') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Belum',
  `file_bukti` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `mitra_id` (`mitra_id`),
  CONSTRAINT `tindak_lanjut_ibfk_1` FOREIGN KEY (`mitra_id`) REFERENCES `mitra_kinerja` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nama` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','pemeriksa','validator','pimpinan','pengampu','pic') COLLATE utf8mb4_unicode_ci NOT NULL,
  `foto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aktif` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `validasi`
--

DROP TABLE IF EXISTS `validasi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `validasi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mitra_id` int NOT NULL,
  `status` enum('BELUM','DISETUJUI','PERLU PERBAIKAN') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BELUM',
  `validator_id` int DEFAULT NULL,
  `tanggal_validasi` date DEFAULT NULL,
  `catatan` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mitra_id` (`mitra_id`),
  KEY `validator_id` (`validator_id`),
  CONSTRAINT `validasi_ibfk_1` FOREIGN KEY (`mitra_id`) REFERENCES `mitra_kinerja` (`id`) ON DELETE CASCADE,
  CONSTRAINT `validasi_ibfk_2` FOREIGN KEY (`validator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-30 18:01:34
