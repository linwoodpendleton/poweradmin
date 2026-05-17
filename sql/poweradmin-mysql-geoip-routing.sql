-- Poweradmin GeoIP routing schema
-- Tables for province/line/ISP-aware DNS resolution.
--
-- Tier 1: dropdown data sources (continents, countries, regions, cities)
--   populated from MaxMind GeoIP2-City CSV via install/helpers/generate_geoip_sql.php
-- Tier 2: routing rules edited via the GeoIP Zone UI
-- Tier 3: (optional) cache of which LUA records have been generated for which
--   record_name so re-renders can be incremental.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `geo_continents` (
  `code`     CHAR(2) NOT NULL,
  `name_en`  VARCHAR(64) NOT NULL,
  `name_zh`  VARCHAR(64) NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `geo_countries` (
  `iso_code`        CHAR(2) NOT NULL,
  `continent_code`  CHAR(2) NOT NULL,
  `name_en`         VARCHAR(128) NOT NULL,
  `name_zh`         VARCHAR(128) NULL,
  PRIMARY KEY (`iso_code`),
  KEY `idx_continent` (`continent_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `geo_regions` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `country_iso`  CHAR(2) NOT NULL,
  `region_code`  VARCHAR(8) NOT NULL,
  `name_en`      VARCHAR(128) NOT NULL,
  `name_zh`      VARCHAR(128) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_country_region` (`country_iso`, `region_code`),
  KEY `idx_country` (`country_iso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `geo_cities` (
  `geoname_id`   INT UNSIGNED NOT NULL,
  `country_iso`  CHAR(2) NOT NULL,
  `region_code`  VARCHAR(8) NULL,
  `name_en`      VARCHAR(128) NOT NULL,
  `name_zh`      VARCHAR(128) NULL,
  PRIMARY KEY (`geoname_id`),
  KEY `idx_country_region` (`country_iso`, `region_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `geo_routing_rules` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain_id`        INT NOT NULL,
  `record_name`      VARCHAR(255) NOT NULL,
  `record_type`      VARCHAR(10) NOT NULL DEFAULT 'A',
  -- Match conditions (NULL = wildcard, all must match)
  `continent_code`   CHAR(2) NULL,
  `country_iso`      CHAR(2) NULL,
  `region_code`      VARCHAR(8) NULL,
  `city_geoname_id`  INT UNSIGNED NULL,
  `isp_pattern`      VARCHAR(128) NULL,
  `domain_pattern`   VARCHAR(128) NULL,
  `connection_type`  ENUM('Cable/DSL','Cellular','Corporate','Satellite') NULL,
  -- Resolution output
  `target`           VARCHAR(255) NOT NULL,
  `weight`           INT NOT NULL DEFAULT 100,
  `priority`         INT NOT NULL DEFAULT 100,
  `enabled`          TINYINT(1) NOT NULL DEFAULT 1,
  `comment`          VARCHAR(255) NULL,
  `created_at`       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`record_name`, `record_type`, `enabled`, `priority`),
  KEY `idx_domain` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
