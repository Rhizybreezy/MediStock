<?php
declare(strict_types=1);

namespace App\Services\ColdChain;

use App\Core\Database;
use App\Core\Gate;
use App\Exceptions\BusinessRuleException;
use App\Repositories\LocationRepository;
use App\Services\Support\AuditService;
use App\Services\Support\NotificationService;

/**
 * Cold-chain temperature observations for refrigerators, freezers and cold rooms.
 * Readings outside the location's configured range are flagged, require a
 * corrective action note, and alert responsible staff immediately.
 */
final class TemperatureService
{
    public function __construct(
        private Database $db,
        private Gate $gate,
        private LocationRepository $locations,
        private NotificationService $notifications,
        private AuditService $audit,
    ) {
    }

    public function record(array $data, int $userId): array
    {
        $loc = $this->locations->find((int) $data['location_id']) ?? throw new BusinessRuleException('Location not found.');
        $this->gate->authorize('temperature.record', (int) $loc['department_id']);
        if ((int) $loc['is_cold_chain'] !== 1) {
            throw new BusinessRuleException('Temperatures are only recorded for cold-chain locations.');
        }
        $temp = round((float) $data['temperature_c'], 2);
        if ($temp < -90 || $temp > 60) {
            throw new BusinessRuleException('Enter a realistic temperature (−90 to 60 °C).');
        }
        $recordedAt = $data['recorded_at'] ?? date('Y-m-d H:i:s');
        if (strtotime($recordedAt) > time() + 300) {
            throw new BusinessRuleException('Readings cannot be recorded in the future.');
        }
        $out = ($loc['min_temp_c'] !== null && $temp < (float) $loc['min_temp_c']) || ($loc['max_temp_c'] !== null && $temp > (float) $loc['max_temp_c']);
        $action = trim((string) ($data['corrective_action'] ?? ''));
        if ($out && $action === '') {
            throw new BusinessRuleException(sprintf('%.1f °C is outside the allowed range (%s–%s °C). Describe the corrective action taken.', $temp, qty($loc['min_temp_c']), qty($loc['max_temp_c'])));
        }
        $id = $this->db->insert('temperature_logs', [
            'location_id' => (int) $loc['id'], 'temperature_c' => $temp,
            'humidity_pct' => isset($data['humidity_pct']) && $data['humidity_pct'] !== null ? round((float) $data['humidity_pct'], 2) : null,
            'is_out_of_range' => $out ? 1 : 0, 'corrective_action' => $action ?: null, 'recorded_by' => $userId, 'recorded_at' => $recordedAt,
        ]);
        if ($out) {
            $this->audit->log('temperature_excursion', 'location', (int) $loc['id'], sprintf('%.1f °C recorded at %s (range %s–%s)', $temp, $loc['name'], qty($loc['min_temp_c']), qty($loc['max_temp_c'])), null, ['action' => $action]);
            $msg = sprintf('%.1f °C at %s (allowed %s–%s °C). Action: %s', $temp, $loc['name'], qty($loc['min_temp_c']), qty($loc['max_temp_c']), $action);
            $alert = ['type' => 'temperature', 'severity' => 'critical', 'title' => "Temperature out of range: {$loc['name']}", 'message' => mb_substr($msg, 0, 500),
                      'link_url' => '/cold-chain?location_id=' . $loc['id'], 'entity_type' => 'location', 'entity_id' => (int) $loc['id'], 'dedupe_key' => 'temp:' . $id];
            $this->notifications->notifyPermission('temperature.record', $alert, (int) $loc['department_id']);
            $this->notifications->notifyPermission('recalls.manage', $alert);
        }
        return ['id' => $id, 'out_of_range' => $out];
    }
}
