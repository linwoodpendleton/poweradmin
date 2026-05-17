<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2010-2026 Poweradmin Development Team
 *  Licensed under GPLv3 — see LICENSE.
 */

declare(strict_types=1);

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\GeoRoutingService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Repository\DbGeoRepository;

/**
 * Add/edit a single GeoIP routing rule.
 *
 * URL: ?page=geo_routing_edit&id=<zone_id>[&rule_id=<rule_id>]
 */
class GeoRoutingEditController extends BaseController
{
    public function run(): void
    {
        $req = $this->getRequest();
        $zoneId = (int)($req['id'] ?? $_GET['id'] ?? 0);
        $ruleId = (int)($req['rule_id'] ?? $_GET['rule_id'] ?? 0);
        if ($zoneId <= 0) {
            $this->showError(_('Missing or invalid zone id.'));
            return;
        }

        $permEdit = Permission::getEditPermission($this->db);
        $isOwner = UserManager::verifyUserIsOwnerZoneId($this->db, $zoneId);
        $this->checkCondition(
            $permEdit === 'none' || (($permEdit === 'own' || $permEdit === 'own_as_client') && !$isOwner),
            _('You do not have permission to manage GeoIP routing for this zone.')
        );

        $service = new GeoRoutingService($this->db, $this->getConfig()->get('database', 'pdns_db_name'));
        $geo = new DbGeoRepository($this->db);

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $rule = $_POST;
            $rule['id'] = $ruleId > 0 ? $ruleId : null;
            $rule['domain_id'] = $zoneId;

            $err = $this->validateRule($rule);
            if ($err !== null) {
                $this->showError($err);
                return;
            }

            $id = $service->saveRule($rule);
            $this->setMessage('geo_routing', 'success',
                $ruleId > 0 ? _('Routing rule updated.') : _('Routing rule created.'));
            $this->redirect('index.php', ['page' => 'geo_routing', 'id' => $zoneId]);
            return;
        }

        $rule = $ruleId > 0 ? $service->getRule($ruleId) : null;
        if ($ruleId > 0 && $rule === null) {
            $this->showError(_('Routing rule not found.'));
            return;
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zoneName = $dnsRecord->getDomainNameById($zoneId) ?? '';

        // Preload continent + (if a country is already chosen) country/region/city
        $continents = $geo->getContinents();
        $countries = $rule && $rule['continent_code'] ? $geo->getCountries($rule['continent_code']) : [];
        $regions = $rule && $rule['country_iso'] ? $geo->getRegions($rule['country_iso']) : [];
        $cities = $rule && $rule['country_iso'] && $rule['region_code']
            ? $geo->getCities($rule['country_iso'], $rule['region_code']) : [];

        $this->render('geo_routing_edit.html', [
            'zone_id' => $zoneId,
            'zone_name' => $zoneName,
            'rule_id' => $ruleId,
            'rule' => $rule ?: $this->blankRule($zoneId),
            'continents' => $continents,
            'countries' => $countries,
            'regions' => $regions,
            'cities' => $cities,
            'connection_types' => ['Cable/DSL', 'Cellular', 'Corporate', 'Satellite'],
        ]);
    }

    private function blankRule(int $zoneId): array
    {
        return [
            'id' => null,
            'domain_id' => $zoneId,
            'record_name' => '',
            'record_type' => 'A',
            'continent_code' => null,
            'country_iso' => null,
            'region_code' => null,
            'city_geoname_id' => null,
            'isp_pattern' => null,
            'domain_pattern' => null,
            'connection_type' => null,
            'target' => '',
            'weight' => 100,
            'priority' => 100,
            'enabled' => 1,
            'comment' => null,
        ];
    }

    private function validateRule(array $rule): ?string
    {
        foreach (['record_name', 'record_type', 'target'] as $f) {
            if (empty($rule[$f])) {
                return sprintf(_('Field "%s" is required.'), $f);
            }
        }
        if (!in_array($rule['record_type'], ['A', 'AAAA', 'CNAME'], true)) {
            return _('record_type must be one of A / AAAA / CNAME.');
        }
        if (strlen((string)$rule['record_name']) > 255 || strlen((string)$rule['target']) > 255) {
            return _('record_name and target must be 255 characters or fewer.');
        }
        return null;
    }
}
