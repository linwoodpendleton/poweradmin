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

/**
 * Lists all GeoIP routing rules for a zone. Allows toggling enabled,
 * deleting, and links to the add/edit page.
 *
 * URL: ?page=geo_routing&id=<zone_id>
 */
class GeoRoutingController extends BaseController
{
    public function run(): void
    {
        $zoneId = $this->getZoneIdOrFail();

        $permEdit = Permission::getEditPermission($this->db);
        $isOwner = UserManager::verifyUserIsOwnerZoneId($this->db, $zoneId);
        $this->checkCondition(
            $permEdit === 'none' || (($permEdit === 'own' || $permEdit === 'own_as_client') && !$isOwner),
            _('You do not have permission to manage GeoIP routing for this zone.')
        );

        $service = new GeoRoutingService($this->db, $this->getConfig()->get('database', 'pdns_db_name'));

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $action = $_POST['action'] ?? '';
            $ruleId = isset($_POST['rule_id']) ? (int)$_POST['rule_id'] : 0;
            if ($action === 'delete' && $ruleId > 0) {
                $service->deleteRule($ruleId);
                $this->setMessage('geo_routing', 'success', _('Routing rule deleted.'));
            } elseif ($action === 'toggle' && $ruleId > 0) {
                $rule = $service->getRule($ruleId);
                if ($rule) {
                    $rule['enabled'] = $rule['enabled'] ? 0 : 1;
                    $service->saveRule($rule);
                    $this->setMessage('geo_routing', 'success', _('Rule status toggled.'));
                }
            }
            $this->redirect('index.php', ['page' => 'geo_routing', 'id' => $zoneId]);
            return;
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zoneName = $dnsRecord->getDomainNameById($zoneId) ?? '';
        $rules = $service->listRulesForDomain($zoneId);

        $this->render('geo_routing.html', [
            'zone_id' => $zoneId,
            'zone_name' => $zoneName,
            'rules' => $rules,
        ]);
    }

    /**
     * Pull the zone id from the request (SymfonyRouter merges path vars into
     * the request array; legacy ?page=foo&id=X also lands there) and bail with
     * a friendly error if it's missing or non-numeric.
     */
    private function getZoneIdOrFail(): int
    {
        $req = $this->getRequest();
        $id = $req['id'] ?? $_GET['id'] ?? null;
        if (!is_numeric($id) || (int)$id <= 0) {
            $this->showError(_('Missing or invalid zone id.'));
        }
        return (int)$id;
    }
}
