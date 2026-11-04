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
-- Table structure for table `designator_types`
--

CREATE TABLE `designator_types` (
  `id_designator_type` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `designator_types`
--

INSERT INTO `designator_types` (`id_designator_type`, `code`, `name`, `description`, `is_active`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'MATERIAL', 'Material', NULL, 1, NULL, NULL, '2026-09-22 20:15:19', '2026-09-22 20:15:19', NULL),
(2, 'JASA', 'Jasa', NULL, 1, NULL, NULL, '2026-09-22 20:15:19', '2026-09-22 20:15:19', NULL),
(3, 'INSTALASI', 'Instalasi', NULL, 1, NULL, NULL, '2026-09-22 20:15:19', '2026-09-22 20:15:19', NULL),
(4, 'PENGUKURAN', 'Pengukuran', NULL, 1, NULL, NULL, '2026-09-22 20:15:19', '2026-09-22 20:15:19', NULL),
(5, 'DISMANTLE', 'Dismantle', NULL, 1, NULL, NULL, '2026-09-22 20:15:19', '2026-09-22 20:15:19', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `designator_types`
--
ALTER TABLE `designator_types`
  ADD PRIMARY KEY (`id_designator_type`),
  ADD UNIQUE KEY `designator_types_code_unique` (`code`),
  ADD KEY `designator_types_created_by_foreign` (`created_by`),
  ADD KEY `designator_types_updated_by_foreign` (`updated_by`),
  ADD KEY `designator_types_is_active_index` (`is_active`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `designator_types`
--
ALTER TABLE `designator_types`
  MODIFY `id_designator_type` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `designator_types`
--
ALTER TABLE `designator_types`
  ADD CONSTRAINT `designator_types_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id_user`) ON DELETE SET NULL,
  ADD CONSTRAINT `designator_types_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id_user`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
