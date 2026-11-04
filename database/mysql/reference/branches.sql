-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Nov 03, 2026 at 09:10 AM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dompis_qe`
--

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id_branch` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `region` varchar(50) DEFAULT NULL,
  `region_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id_branch`, `code`, `name`, `region`, `region_id`, `is_active`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) VALUES
(2, 'SDA', 'SIDOARJO', 'REGION JATIM', 1, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:51', NULL, NULL, NULL),
(3, 'SBY', 'SURABAYA', 'REGION JATIM', 1, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:51', NULL, NULL, NULL),
(4, 'MDN', 'MADIUN', 'REGION JATIM', 1, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:51', NULL, NULL, NULL),
(5, 'JBR', 'JEMBER', 'REGION JATIM', 1, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:51', NULL, NULL, NULL),
(6, 'LMG', 'LAMONGAN', 'REGION JATIM', 1, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:51', NULL, NULL, NULL),
(7, 'MLG', 'MALANG', 'REGION JATIM', 1, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:51', NULL, NULL, NULL),
(8, 'YGY', 'YOGYAKARTA', 'REGION JATENG DIY', 2, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:48', NULL, NULL, NULL),
(9, 'SMG', 'SEMARANG', 'REGION JATENG DIY', 2, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:48', NULL, NULL, NULL),
(10, 'PWT', 'PURWOKERTO', 'REGION JATENG DIY', 2, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:48', NULL, NULL, NULL),
(11, 'PKL', 'PEKALONGAN', 'REGION JATENG DIY', 2, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:48', NULL, NULL, NULL),
(12, 'SKA', 'SURAKARTA', 'REGION JATENG DIY', 2, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:48', NULL, NULL, NULL),
(13, 'MGL', 'MAGELANG', 'REGION JATENG DIY', 2, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:48', NULL, NULL, NULL),
(14, 'DPS', 'DENPASAR', 'REGION BALNUS', 3, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:45', NULL, NULL, NULL),
(15, 'KPG', 'KUPANG', 'REGION BALNUS', 3, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:45', NULL, NULL, NULL),
(16, 'MTR', 'MATARAM', 'REGION BALNUS', 3, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:45', NULL, NULL, NULL),
(17, 'FLS', 'FLORES', 'REGION BALNUS', 3, 1, '2026-08-30 06:21:27', '2026-09-23 20:51:45', NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id_branch`),
  ADD UNIQUE KEY `branches_code_unique` (`code`),
  ADD KEY `branches_region_name_idx` (`region`,`name`),
  ADD KEY `branches_region_id_foreign` (`region_id`),
  ADD KEY `branches_created_by_foreign` (`created_by`),
  ADD KEY `branches_updated_by_foreign` (`updated_by`),
  ADD KEY `branches_is_active_index` (`is_active`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id_branch` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `branches`
--
ALTER TABLE `branches`
  ADD CONSTRAINT `branches_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id_user`) ON DELETE SET NULL,
  ADD CONSTRAINT `branches_region_id_foreign` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id_region`) ON DELETE SET NULL,
  ADD CONSTRAINT `branches_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id_user`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
