<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2010-2026 Poweradmin Development Team
 *  Licensed under GPLv3 — see LICENSE.
 */

declare(strict_types=1);

namespace Poweradmin\Infrastructure\Repository;

use PDO;

/**
 * Read-only access to the geo_continents / geo_countries / geo_regions /
 * geo_cities reference tables that populate the GeoIP Zone editor's
 * cascading dropdowns.
 *
 * Tables are populated by install/helpers/generate_geoip_sql.php from
 * MaxMind GeoIP2-City-Locations CSVs. Schema is defined in
 * sql/poweradmin-mysql-geoip-routing.sql.
 */
class DbGeoRepository
{
    private object $db;

    public function __construct(object $db)
    {
        $this->db = $db;
    }

    /** @return array<int, array{code:string, name_en:string, name_zh:?string}> */
    public function getContinents(): array
    {
        $stmt = $this->db->prepare(
            'SELECT code, name_en, name_zh FROM geo_continents ORDER BY name_en'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array{iso_code:string, name_en:string, name_zh:?string}> */
    public function getCountries(?string $continentCode = null): array
    {
        $sql = 'SELECT iso_code, continent_code, name_en, name_zh FROM geo_countries';
        $params = [];
        if ($continentCode !== null && $continentCode !== '') {
            $sql .= ' WHERE continent_code = :cc';
            $params[':cc'] = $continentCode;
        }
        $sql .= ' ORDER BY name_en';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array{region_code:string, name_en:string, name_zh:?string}> */
    public function getRegions(string $countryIso): array
    {
        $stmt = $this->db->prepare(
            'SELECT region_code, name_en, name_zh FROM geo_regions
             WHERE country_iso = :iso ORDER BY name_en'
        );
        $stmt->execute([':iso' => $countryIso]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array{geoname_id:int, name_en:string, name_zh:?string}> */
    public function getCities(string $countryIso, ?string $regionCode = null, int $limit = 500): array
    {
        $sql = 'SELECT geoname_id, name_en, name_zh FROM geo_cities WHERE country_iso = :iso';
        $params = [':iso' => $countryIso];
        if ($regionCode !== null && $regionCode !== '') {
            $sql .= ' AND region_code = :rc';
            $params[':rc'] = $regionCode;
        }
        $sql .= ' ORDER BY name_en LIMIT ' . (int)$limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
