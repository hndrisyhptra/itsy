-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Nov 03, 2026 at 09:11 AM
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
-- Table structure for table `regions`
--

CREATE TABLE `regions` (
  `id_region` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(255) NOT NULL,
  `area_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `regions`
--

INSERT INTO `regions` (`id_region`, `code`, `area_id`, `name`, `is_active`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'JATIM', 1, 'REGION JATIM', 1, NULL, 2, '2026-09-22 20:15:19', '2026-09-23 20:51:51', NULL),
(2, 'JATENGDIY', 1, 'REGION JATENG DIY', 1, NULL, 2, '2026-09-22 20:15:19', '2026-09-23 20:51:48', NULL),
(3, 'BALNUS', 1, 'REGION BALNUS', 1, NULL, 2, '2026-09-22 20:15:19', '2026-09-23 20:51:45', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `regions`
--
ALTER TABLE `regions`
  ADD PRIMARY KEY (`id_region`),
  ADD UNIQUE KEY `regions_code_unique` (`code`),
  ADD KEY `regions_created_by_foreign` (`created_by`),
  ADD KEY `regions_updated_by_foreign` (`updated_by`),
  ADD KEY `regions_is_active_index` (`is_active`),
  ADD KEY `regions_area_id_foreign` (`area_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `regions`
--
ALTER TABLE `regions`
  MODIFY `id_region` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `regions`
--
ALTER TABLE `regions`
  ADD CONSTRAINT `regions_area_id_foreign` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id_area`) ON DELETE SET NULL,
  ADD CONSTRAINT `regions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id_user`) ON DELETE SET NULL,
  ADD CONSTRAINT `regions_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id_user`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
