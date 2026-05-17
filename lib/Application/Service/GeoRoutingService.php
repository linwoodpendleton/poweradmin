<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2010-2026 Poweradmin Development Team
 *  Licensed under GPLv3 — see LICENSE.
 */

declare(strict_types=1);

namespace Poweradmin\Application\Service;

use PDO;

/**
 * CRUD for geo_routing_rules + on-demand LUA record (re)generation in the
 * PowerDNS gmysqlbackend `records` table so the rules take effect at
 * resolve time.
 *
 * Each unique (domain_id, record_name, record_type) becomes a single
 * `LUA` record whose Lua body queries geo_routing_rules and picks the
 * first matching target. Rules can be added/edited/removed via the UI;
 * after each mutation regenerateLua() rewrites the LUA record so PowerDNS
 * reads the latest rule set on its next cache miss.
 */
class GeoRoutingService
{
    private object $db;
    private ?string $pdnsDb;

    public function __construct(object $db, ?string $pdnsDb = null)
    {
        $this->db = $db;
        $this->pdnsDb = $pdnsDb;
    }

    /** @return array<int, array<string,mixed>> */
    public function listRulesForDomain(int $domainId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM geo_routing_rules WHERE domain_id = :did
             ORDER BY record_name, record_type, priority ASC, weight DESC'
        );
        $stmt->execute([':did' => $domainId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function getRule(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM geo_routing_rules WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveRule(array $rule): int
    {
        $rule = $this->normalize($rule);
        if (!empty($rule['id'])) {
            $sql = 'UPDATE geo_routing_rules SET
                domain_id = :domain_id, record_name = :record_name, record_type = :record_type,
                continent_code = :continent_code, country_iso = :country_iso, region_code = :region_code,
                city_geoname_id = :city_geoname_id, isp_pattern = :isp_pattern,
                domain_pattern = :domain_pattern, connection_type = :connection_type,
                target = :target, weight = :weight, priority = :priority,
                enabled = :enabled, comment = :comment
                WHERE id = :id';
            $stmt = $this->db->prepare($sql);
            $params = $this->bindParams($rule, true);
            $stmt->execute($params);
            $id = (int)$rule['id'];
        } else {
            $sql = 'INSERT INTO geo_routing_rules
                (domain_id, record_name, record_type, continent_code, country_iso, region_code,
                 city_geoname_id, isp_pattern, domain_pattern, connection_type,
                 target, weight, priority, enabled, comment)
                VALUES
                (:domain_id, :record_name, :record_type, :continent_code, :country_iso, :region_code,
                 :city_geoname_id, :isp_pattern, :domain_pattern, :connection_type,
                 :target, :weight, :priority, :enabled, :comment)';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($this->bindParams($rule, false));
            $id = (int)$this->db->lastInsertId();
        }
        $this->regenerateLua($rule['domain_id'], $rule['record_name'], $rule['record_type']);
        return $id;
    }

    public function deleteRule(int $id): void
    {
        $rule = $this->getRule($id);
        if (!$rule) return;
        $stmt = $this->db->prepare('DELETE FROM geo_routing_rules WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $this->regenerateLua(
            (int)$rule['domain_id'],
            (string)$rule['record_name'],
            (string)$rule['record_type']
        );
    }

    private function normalize(array $r): array
    {
        return [
            'id'              => $r['id'] ?? null,
            'domain_id'       => (int)($r['domain_id'] ?? 0),
            'record_name'     => trim((string)($r['record_name'] ?? '')),
            'record_type'     => strtoupper(trim((string)($r['record_type'] ?? 'A'))),
            'continent_code'  => $this->nullIfEmpty($r['continent_code'] ?? null),
            'country_iso'     => $this->nullIfEmpty($r['country_iso'] ?? null),
            'region_code'     => $this->nullIfEmpty($r['region_code'] ?? null),
            'city_geoname_id' => isset($r['city_geoname_id']) && $r['city_geoname_id'] !== ''
                ? (int)$r['city_geoname_id'] : null,
            'isp_pattern'     => $this->nullIfEmpty($r['isp_pattern'] ?? null),
            'domain_pattern'  => $this->nullIfEmpty($r['domain_pattern'] ?? null),
            'connection_type' => $this->nullIfEmpty($r['connection_type'] ?? null),
            'target'          => trim((string)($r['target'] ?? '')),
            'weight'          => isset($r['weight']) ? (int)$r['weight'] : 100,
            'priority'        => isset($r['priority']) ? (int)$r['priority'] : 100,
            'enabled'         => !empty($r['enabled']) ? 1 : 0,
            'comment'         => $this->nullIfEmpty($r['comment'] ?? null),
        ];
    }

    private function nullIfEmpty($v): ?string
    {
        if ($v === null) return null;
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    private function bindParams(array $r, bool $withId): array
    {
        $p = [
            ':domain_id'       => $r['domain_id'],
            ':record_name'     => $r['record_name'],
            ':record_type'     => $r['record_type'],
            ':continent_code'  => $r['continent_code'],
            ':country_iso'     => $r['country_iso'],
            ':region_code'     => $r['region_code'],
            ':city_geoname_id' => $r['city_geoname_id'],
            ':isp_pattern'     => $r['isp_pattern'],
            ':domain_pattern'  => $r['domain_pattern'],
            ':connection_type' => $r['connection_type'],
            ':target'          => $r['target'],
            ':weight'          => $r['weight'],
            ':priority'        => $r['priority'],
            ':enabled'         => $r['enabled'],
            ':comment'         => $r['comment'],
        ];
        if ($withId) $p[':id'] = (int)$r['id'];
        return $p;
    }

    /**
     * Build the LUA record body for one record_name/record_type and persist
     * it as a `LUA` record under the matching PowerDNS domain. Removes any
     * stale LUA record if no rules remain.
     */
    public function regenerateLua(int $domainId, string $recordName, string $recordType): void
    {
        $rules = $this->getActiveRulesFor($domainId, $recordName, $recordType);
        $recordsTable = $this->qualifyPdnsTable('records');

        // Find any existing LUA record(s) we previously emitted.
        $find = $this->db->prepare(
            "SELECT id FROM {$recordsTable} WHERE domain_id = :did AND name = :name AND type = 'LUA'"
        );
        $find->execute([':did' => $domainId, ':name' => $recordName]);
        $existingIds = $find->fetchAll(PDO::FETCH_COLUMN);

        if (!$rules) {
            if ($existingIds) {
                $in = implode(',', array_map('intval', $existingIds));
                $this->db->exec("DELETE FROM {$recordsTable} WHERE id IN ($in)");
            }
            return;
        }

        $luaBody = $this->renderLuaBody($recordType, $rules);
        // PowerDNS LUA record content format: "<inner-type> \"<lua-code>\""
        $innerType = $recordType === 'AAAA' ? 'AAAA' : ($recordType === 'CNAME' ? 'CNAME' : 'A');
        $content = $innerType . ' ' . self::quoteLua($luaBody);

        if ($existingIds) {
            $update = $this->db->prepare(
                "UPDATE {$recordsTable} SET content = :content, ttl = 60 WHERE id = :id"
            );
            $update->execute([':content' => $content, ':id' => (int)$existingIds[0]]);
            if (count($existingIds) > 1) {
                $extras = array_slice($existingIds, 1);
                $in = implode(',', array_map('intval', $extras));
                $this->db->exec("DELETE FROM {$recordsTable} WHERE id IN ($in)");
            }
        } else {
            $insert = $this->db->prepare(
                "INSERT INTO {$recordsTable} (domain_id, name, type, content, ttl, prio, disabled)
                 VALUES (:did, :name, 'LUA', :content, 60, 0, 0)"
            );
            $insert->execute([
                ':did'     => $domainId,
                ':name'    => $recordName,
                ':content' => $content,
            ]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function getActiveRulesFor(int $domainId, string $recordName, string $recordType): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM geo_routing_rules
              WHERE domain_id = :did AND record_name = :name AND record_type = :type AND enabled = 1
              ORDER BY priority ASC, weight DESC'
        );
        $stmt->execute([':did' => $domainId, ':name' => $recordName, ':type' => $recordType]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Build a self-contained Lua body that PowerDNS evaluates per query.
     *
     * PowerDNS LUA records prepend `return ` to the body, so we wrap the
     * statements in an immediately-invoked function: `(function() ... end)()`.
     * GeoIP results from `geoiplookup` come back lowercased, so the rule
     * comparison values are lowercased here too. We use Lua `[[...]]`
     * string literals throughout so the body can be embedded in the SQL
     * `records.content` column ("A "<lua>"") without escaping inner double
     * quotes.
     */
    private function renderLuaBody(string $recordType, array $rules): string
    {
        $lines = [];
        $lines[] = 'local ip=bestwho:toString()';
        $lines[] = 'local co=geoiplookup(ip,GeoIPQueryAttribute.Country) or [[]]';
        $lines[] = 'local cn=geoiplookup(ip,GeoIPQueryAttribute.Continent) or [[]]';
        $lines[] = 'local re=geoiplookup(ip,GeoIPQueryAttribute.Region) or [[]]';
        $lines[] = 'local ci=geoiplookup(ip,GeoIPQueryAttribute.City) or [[]]';
        $lines[] = 'local isp=geoiplookup(ip,GeoIPQueryAttribute.ISP) or [[]]';
        $lines[] = 'local dm=geoiplookup(ip,GeoIPQueryAttribute.Domain) or [[]]';
        $lines[] = 'local ct=geoiplookup(ip,GeoIPQueryAttribute.ConnectionType) or [[]]';

        $default = null;
        foreach ($rules as $r) {
            if ($this->isWildcard($r)) {
                if ($default === null) $default = $r['target'];
                continue;
            }
            $conds = $this->buildLuaConditions($r);
            $target = self::luaBracketString((string)$r['target']);
            $lines[] = 'if ' . implode(' and ', $conds) . ' then return ' . $target . ' end';
        }
        $lines[] = 'return ' . self::luaBracketString($default !== null ? (string)$default : '0.0.0.0');

        return '(function() ' . implode('; ', $lines) . ' end)()';
    }

    private function isWildcard(array $r): bool
    {
        foreach (['continent_code','country_iso','region_code','city_geoname_id','isp_pattern','domain_pattern','connection_type'] as $k) {
            if ($r[$k] !== null && $r[$k] !== '') return false;
        }
        return true;
    }

    /** @return array<int,string> */
    private function buildLuaConditions(array $r): array
    {
        $c = [];
        // GeoIP attribute values are returned lowercased; compare in kind.
        if (!empty($r['continent_code'])) {
            $c[] = 'cn==' . self::luaBracketString(strtolower((string)$r['continent_code']));
        }
        if (!empty($r['country_iso'])) {
            $c[] = 'co==' . self::luaBracketString(strtolower((string)$r['country_iso']));
        }
        if (!empty($r['region_code'])) {
            $c[] = 're==' . self::luaBracketString(strtolower((string)$r['region_code']));
        }
        if (!empty($r['city_geoname_id'])) {
            $cityName = $this->getCityName((int)$r['city_geoname_id']);
            if ($cityName !== null) {
                $c[] = 'ci==' . self::luaBracketString(strtolower($cityName));
            }
        }
        if (!empty($r['isp_pattern'])) {
            $c[] = 'string.find(isp,' . self::luaBracketString(strtolower((string)$r['isp_pattern'])) . ',1,true)~=nil';
        }
        if (!empty($r['domain_pattern'])) {
            $c[] = 'string.find(dm,' . self::luaBracketString(strtolower((string)$r['domain_pattern'])) . ',1,true)~=nil';
        }
        if (!empty($r['connection_type'])) {
            $c[] = 'ct==' . self::luaBracketString(strtolower((string)$r['connection_type']));
        }
        return $c;
    }

    private function getCityName(int $geonameId): ?string
    {
        $stmt = $this->db->prepare('SELECT name_en FROM geo_cities WHERE geoname_id = :id');
        $stmt->execute([':id' => $geonameId]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string)$name;
    }

    private function qualifyPdnsTable(string $table): string
    {
        return $this->pdnsDb ? "`{$this->pdnsDb}`.`{$table}`" : "`{$table}`";
    }

    /**
     * Render a Lua long-bracket string literal: `[[content]]`. Chosen so the
     * Lua body can be embedded verbatim inside the SQL records.content column
     * (`A "<lua>"`) without ever needing inner double-quote escaping. If the
     * payload happens to contain `]]`, we widen to `[==[...]==]`.
     */
    public static function luaBracketString(string $s): string
    {
        $level = 0;
        while (strpos($s, '[' . str_repeat('=', $level) . '[') !== false
            || strpos($s, ']' . str_repeat('=', $level) . ']') !== false) {
            $level++;
        }
        $eq = str_repeat('=', $level);
        // Newlines inside Lua long brackets are valid but ugly in a one-liner;
        // normalise to spaces.
        $s = str_replace(["\n", "\r"], ' ', $s);
        return '[' . $eq . '[' . $s . ']' . $eq . ']';
    }

    /**
     * Wrap a Lua program body for embedding in a PowerDNS LUA record's
     * `content` column. PowerDNS expects: <RTYPE> "<lua-code>". Inner double
     * quotes inside the lua code must be escaped as \\". Because
     * `luaBracketString` already eliminates inner double quotes, the escape
     * step is a no-op in normal cases but kept for safety.
     */
    public static function quoteLua(string $body): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $body) . '"';
    }
}
