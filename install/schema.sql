-- phpMyAdmin SQL Dump
-- version 4.9.5deb2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 30, 2021 at 04:53 AM
-- Server version: 8.0.27-0ubuntu0.20.04.1
-- PHP Version: 7.4.3

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Database: `bgcdn_main`
--
CREATE DATABASE IF NOT EXISTS `bgcdn_main` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `bgcdn_main`;

-- --------------------------------------------------------

--
-- Table structure for table `bandwidth_logs`
--

CREATE TABLE `bandwidth_logs` (
  `month` date NOT NULL,
  `bytes_out` bigint UNSIGNED NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `config`
--

CREATE TABLE `config` (
  `property` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `value` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `log_events`
--

CREATE TABLE `log_events` (
  `id` bigint UNSIGNED NOT NULL,
  `event_type_id` smallint UNSIGNED NOT NULL,
  `log_ts` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `message` text,
  `exception_data` text,
  `data` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `log_event_types`
--

CREATE TABLE `log_event_types` (
  `event_type` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `id` smallint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `server_status`
--

CREATE TABLE `server_status` (
  `property` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `value` json NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transcoding_jobs`
--

CREATE TABLE `transcoding_jobs` (
  `id` int UNSIGNED NOT NULL,
  `src_filename` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `src_is_new` tinyint(1) NOT NULL,
  `src_extension` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `src_size_bytes` int UNSIGNED NOT NULL,
  `src_duration` float UNSIGNED NOT NULL,
  `version_filename` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `version_width` smallint UNSIGNED NOT NULL,
  `version_height` smallint UNSIGNED NOT NULL,
  `job_settings` json NOT NULL,
  `job_started` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `job_finished` timestamp GENERATED ALWAYS AS ((case when ((`transcode_finished` is not null) and (`cloud_upload_finished` is not null) and ((`src_is_new` = 0) or (`src_cloud_upload_finished` is not null))) then greatest(`transcode_finished`,`cloud_upload_finished`) else NULL end)) VIRTUAL NULL,
  `progress_token` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `docker_container_id` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `src_cloud_upload_started` timestamp NULL DEFAULT NULL,
  `src_cloud_upload_finished` timestamp NULL DEFAULT NULL,
  `cloud_upload_started` timestamp NULL DEFAULT NULL,
  `cloud_upload_finished` timestamp NULL DEFAULT NULL,
  `flag_cloud_download_src` tinyint(1) NOT NULL DEFAULT '0',
  `cloud_download_src_in_progress` tinyint(1) NOT NULL DEFAULT '0',
  `transcode_ready` tinyint(1) NOT NULL DEFAULT '0',
  `transcode_started` timestamp NULL DEFAULT NULL,
  `transcode_is_active` tinyint(1) GENERATED ALWAYS AS ((case when ((`transcode_started` is not null) and (`transcode_finished` is null)) then 1 else 0 end)) VIRTUAL NOT NULL,
  `transcode_finished` timestamp NULL DEFAULT NULL,
  `transcode_is_finished` tinyint(1) GENERATED ALWAYS AS ((`transcode_finished` is not null)) VIRTUAL NOT NULL,
  `transcode_fail_code` json DEFAULT NULL,
  `transcode_fail_output` json DEFAULT NULL,
  `hub_return_meta` json DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bandwidth_logs`
--
ALTER TABLE `bandwidth_logs`
  ADD PRIMARY KEY (`month`);

--
-- Indexes for table `config`
--
ALTER TABLE `config`
  ADD PRIMARY KEY (`property`);

--
-- Indexes for table `log_events`
--
ALTER TABLE `log_events`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `log_event_types`
--
ALTER TABLE `log_event_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `event_type` (`event_type`);

--
-- Indexes for table `server_status`
--
ALTER TABLE `server_status`
  ADD PRIMARY KEY (`property`);

--
-- Indexes for table `transcoding_jobs`
--
ALTER TABLE `transcoding_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `progress_token` (`progress_token`),
  ADD KEY `src_is_new` (`src_is_new`,`src_cloud_upload_started`) USING BTREE,
  ADD KEY `flag_cloud_download_src` (`flag_cloud_download_src`),
  ADD KEY `transcode_is_finished` (`transcode_is_finished`,`cloud_upload_started`),
  ADD KEY `job_finished` (`job_finished`),
  ADD KEY `transcode_is_active` (`transcode_is_active`),
  ADD KEY `transcode_ready` (`transcode_ready`,`transcode_is_active`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `log_events`
--
ALTER TABLE `log_events`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `log_event_types`
--
ALTER TABLE `log_event_types`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transcoding_jobs`
--
ALTER TABLE `transcoding_jobs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;
