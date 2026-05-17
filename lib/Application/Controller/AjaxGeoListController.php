<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2010-2026 Poweradmin Development Team
 *  Licensed under GPLv3 — see LICENSE.
 */

declare(strict_types=1);

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Repository\DbGeoRepository;

/**
 * AJAX JSON endpoint for the cascading country / region / city dropdowns
 * on the GeoIP routing edit page.
 *
 * Usage:
 *   ?page=ajax_geo_list&kind=countries&continent=AS
 *   ?page=ajax_geo_list&kind=regions&country=CN
 *   ?page=ajax_geo_list&kind=cities&country=CN&region=GD
 *
 * Returns an array of `{value, label}` objects. Continents have value=code,
 * countries have value=iso_code, regions have value=region_code, cities have
 * value=geoname_id.
 */
class AjaxGeoListController extends BaseController
{
    public function run(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $repo = new DbGeoRepository($this->db);
        $kind = $_GET['kind'] ?? '';
        $result = [];

        try {
            switch ($kind) {
                case 'continents':
                    foreach ($repo->getContinents() as $row) {
                        $result[] = [
                            'value' => $row['code'],
                            'label' => $this->pickLabel($row),
                        ];
                    }
                    break;
                case 'countries':
                    $continent = $_GET['continent'] ?? null;
                    foreach ($repo->getCountries($continent) as $row) {
                        $result[] = [
                            'value' => $row['iso_code'],
                            'label' => $this->pickLabel($row),
                        ];
                    }
                    break;
                case 'regions':
                    $country = $_GET['country'] ?? '';
                    if ($country === '') break;
                    foreach ($repo->getRegions($country) as $row) {
                        $result[] = [
                            'value' => $row['region_code'],
                            'label' => $this->pickLabel($row),
                        ];
                    }
                    break;
                case 'cities':
                    $country = $_GET['country'] ?? '';
                    $region = $_GET['region'] ?? null;
                    if ($country === '') break;
                    foreach ($repo->getCities($country, $region) as $row) {
                        $result[] = [
                            'value' => (int)$row['geoname_id'],
                            'label' => $this->pickLabel($row),
                        ];
                    }
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'unknown kind']);
                    exit;
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function pickLabel(array $row): string
    {
        if (!empty($row['name_zh'])) {
            return $row['name_zh'] . ' (' . $row['name_en'] . ')';
        }
        return (string)$row['name_en'];
    }
}
