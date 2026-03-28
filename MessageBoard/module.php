<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MessageStore.php';

/**
 * MessageBoard – Zentrale Meldungsanzeige für IP-Symcon 7.x / 8.x
 *
 * Verwaltet Meldungen mit Prioritäten, Quittierung und Verfallszeit.
 * Erzeugt eine HTML-Box Variable für WebFront und IPSView
 * sowie eine Tile-Visualisierung für die Kachel-Visu.
 * Quittierung direkt aus der HTML-Anzeige via Webhook.
 */
class MessageBoard extends IPSModule
{
    private const WEBHOOK_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    // ═════════════════════════════════════════════════════════════
    //  Modul-Lifecycle
    // ═════════════════════════════════════════════════════════════

    public function Create()
    {
        parent::Create();

        // ── Properties (Konfiguration) ──
        $this->RegisterPropertyInteger('MaxMessages', 50);
        $this->RegisterPropertyInteger('CleanupInterval', 60);
        $this->RegisterPropertyString('WatchedVariables', '[]');
        $this->RegisterPropertyBoolean('ShowAcknowledgeAll', true);
        $this->RegisterPropertyBoolean('RemoveOnAcknowledge', false); // true = entfernen statt ausgrauen
        $this->RegisterPropertyInteger('SortOrder', 0);

        // ── Attribute (interne Datenhaltung) ──
        $this->RegisterAttributeString('Messages', '[]');

        // ── Timer ──
        $this->RegisterTimer('CleanupExpired', 0, 'MSGBOARD_CleanupExpired($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // ── HTML-Box Variable ──
        $this->MaintainVariable('HTMLBox', 'Meldungsanzeige', VARIABLETYPE_STRING, '~HTMLBox', 1, true);

        // ── Zähler-Variable ──
        $this->MaintainVariable('MessageCount', 'Anzahl Meldungen', VARIABLETYPE_INTEGER, '', 2, true);

        // ── Webhook registrieren ──
        $this->RegisterHookIfNeeded('/hook/msgboard/' . $this->InstanceID);

        // Variable-Überwachung (Phase 2)
        $watched = json_decode($this->ReadPropertyString('WatchedVariables'), true);
        if (is_array($watched)) {
            foreach ($watched as $entry) {
                if (isset($entry['VariableID']) && $entry['VariableID'] > 0) {
                    $this->RegisterMessage($entry['VariableID'], VM_UPDATE);
                }
            }
        }

        // Cleanup-Timer
        $interval = $this->ReadPropertyInteger('CleanupInterval');
        $this->SetTimerInterval('CleanupExpired', $interval * 1000);

        // HTML initial rendern
        $this->updateVisualization();

        $this->SetStatus(102);
    }

    public function Destroy()
    {
        // Webhook aufräumen bei Instanz-Löschung
        if (!IPS_InstanceExists($this->InstanceID)) {
            $this->UnregisterHook('/hook/msgboard/' . $this->InstanceID);
        }
        parent::Destroy();
    }

    // ═════════════════════════════════════════════════════════════
    //  Webhook – Quittierung aus der HTML-Box
    // ═════════════════════════════════════════════════════════════

    /**
     * Wird aufgerufen wenn ein Link in der HTML-Box geklickt wird.
     */
    protected function ProcessHookData()
    {
        $action = $_GET['action'] ?? '';
        $id = $_GET['id'] ?? '';

        $this->SendDebug('Webhook', "action=$action, id=$id", 0);

        $removeOnAck = $this->ReadPropertyBoolean('RemoveOnAcknowledge');

        switch ($action) {
            case 'ack':
                if ($removeOnAck) {
                    $this->RemoveMessage($id);
                } else {
                    $this->AcknowledgeMessage($id);
                }
                break;

            case 'remove':
                $this->RemoveMessage($id);
                break;

            case 'clearall':
                $this->ClearAll();
                break;

            default:
                $this->SendDebug('Webhook', 'Unbekannte Aktion: ' . $action, 0);
        }

        // Antwort: leeres 1x1 Pixel (verhindert Browser-Navigation)
        header('HTTP/1.1 200 OK');
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    // ═════════════════════════════════════════════════════════════
    //  Event-Eingang (Phase 2)
    // ═════════════════════════════════════════════════════════════

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE) {
            $this->SendDebug('MessageSink', sprintf(
                'Variable %d aktualisiert: %s', $SenderID, strval($Data[0])
            ), 0);
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  Konfigurationsformular dynamisch befüllen
    // ═════════════════════════════════════════════════════════════

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $store = $this->loadMessages();
        $messages = $store->getAll();
        $listValues = [];

        foreach ($messages as $msg) {
            $prioLabels = [0 => 'Info', 1 => 'Hinweis', 2 => 'Warnung', 3 => 'Alarm'];
            $listValues[] = [
                'priority'     => $prioLabels[$msg['priority']] ?? 'Info',
                'icon'         => $msg['icon'],
                'text'         => $msg['text'],
                'timestamp'    => date('d.m.Y H:i:s', $msg['timestamp']),
                'expires'      => $msg['expires'] ? date('d.m.Y H:i:s', $msg['expires']) : '–',
                'acknowledged' => $msg['acknowledged'] ? 'Ja' : 'Nein',
            ];
        }

        foreach ($form['actions'] as &$action) {
            if (isset($action['name']) && $action['name'] === 'MessageList') {
                $action['values'] = $listValues;
                break;
            }
        }

        return json_encode($form);
    }

    // ═════════════════════════════════════════════════════════════
    //  RequestAction (Tile-Klick Handler)
    // ═════════════════════════════════════════════════════════════

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'AcknowledgeMessage':
                $removeOnAck = $this->ReadPropertyBoolean('RemoveOnAcknowledge');
                if ($removeOnAck) {
                    $this->RemoveMessage($Value);
                } else {
                    $this->AcknowledgeMessage($Value);
                }
                break;
            case 'AcknowledgeAll':
                $this->ClearAll();
                break;
            default:
                $this->SendDebug('RequestAction', 'Unbekannte Aktion: ' . $Ident, 0);
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  Öffentliche API (Präfix: MSGBOARD_)
    // ═════════════════════════════════════════════════════════════

    public function AddMessage(string $Text, int $Priority = 0, string $Icon = 'Information', int $TTL = 0): string
    {
        $store = $this->loadMessages();

        $max = $this->ReadPropertyInteger('MaxMessages');
        if ($store->count() >= $max) {
            $this->SendDebug('AddMessage', 'Max. Meldungsanzahl erreicht (' . $max . ')', 0);
            $this->removeOldestLowPriority($store);
        }

        $id = $store->add($Text, $Priority, $Icon, $TTL);
        $this->persistMessages($store);
        $this->updateVisualization();

        $this->SendDebug('AddMessage', sprintf(
            '[%s] %s (Prio: %d, TTL: %ds)', $id, $Text, $Priority, $TTL
        ), 0);

        return $id;
    }

    public function RemoveMessage(string $MessageID): bool
    {
        $store = $this->loadMessages();
        $result = $store->remove($MessageID);
        if ($result) {
            $this->persistMessages($store);
            $this->updateVisualization();
            $this->SendDebug('RemoveMessage', $MessageID, 0);
        }
        return $result;
    }

    public function AcknowledgeMessage(string $MessageID): bool
    {
        $store = $this->loadMessages();
        $result = $store->acknowledge($MessageID);
        if ($result) {
            $this->persistMessages($store);
            $this->updateVisualization();
            $this->SendDebug('AcknowledgeMessage', $MessageID, 0);
        }
        return $result;
    }

    public function ClearAll(): void
    {
        $store = $this->loadMessages();
        $store->clearAll();
        $this->persistMessages($store);
        $this->updateVisualization();
        $this->SendDebug('ClearAll', 'Alle Meldungen gelöscht', 0);
    }

    public function ClearByPriority(int $Priority): void
    {
        $store = $this->loadMessages();
        $count = $store->clearByPriority($Priority);
        $this->persistMessages($store);
        $this->updateVisualization();
        $this->SendDebug('ClearByPriority', "$count Meldung(en) Prio $Priority gelöscht", 0);
    }

    public function GetMessages(): array
    {
        return $this->loadMessages()->getAll();
    }

    public function GetMessageCount(): int
    {
        return $this->loadMessages()->count();
    }

    public function GetMessageCountByPriority(int $Priority): int
    {
        return $this->loadMessages()->countByPriority($Priority);
    }

    // ═════════════════════════════════════════════════════════════
    //  Timer-Callback
    // ═════════════════════════════════════════════════════════════

    public function CleanupExpired(): void
    {
        $store = $this->loadMessages();
        $removed = $store->removeExpired();
        if ($removed > 0) {
            $this->persistMessages($store);
            $this->updateVisualization();
            $this->SendDebug('CleanupExpired', "$removed abgelaufene Meldung(en) entfernt", 0);
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  Tile-Visualisierung
    // ═════════════════════════════════════════════════════════════

    public function GetVisualizationTile(): string
    {
        return $this->renderTileHTML();
    }

    // ═════════════════════════════════════════════════════════════
    //  Private Hilfsmethoden
    // ═════════════════════════════════════════════════════════════

    private function loadMessages(): MessageStore
    {
        $store = new MessageStore();
        $store->fromJSON($this->ReadAttributeString('Messages'));
        return $store;
    }

    private function persistMessages(MessageStore $store): void
    {
        $this->WriteAttributeString('Messages', $store->toJSON());
    }

    /**
     * HTML-Box + Tile + Zähler aktualisieren.
     */
    private function updateVisualization(): void
    {
        $store = $this->loadMessages();
        $this->SetValue('HTMLBox', $this->renderHTMLBox($store));
        $this->SetValue('MessageCount', $store->count());
        $this->UpdateVisualizationValue($this->renderTileDataJSON($store));
    }

    /**
     * Webhook-URL für diese Instanz.
     */
    private function getWebhookURL(): string
    {
        return '/hook/msgboard/' . $this->InstanceID;
    }

    // ─── HTML-Box (WebFront + IPSView) ───────────────────────────

    private function renderHTMLBox(MessageStore $store): string
    {
        $messages = $store->getAll();
        $count = $store->count();
        $activeCount = 0;
        foreach ($messages as $m) {
            if (!$m['acknowledged']) {
                $activeCount++;
            }
        }

        $hookBase = $this->getWebhookURL();
        $showAckAll = $this->ReadPropertyBoolean('ShowAcknowledgeAll');

        // ── CSS ──
        $html = '<style>
.mb{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;width:100%;border-collapse:collapse;background:#1e1e2e;color:#cdd6f4;border-radius:8px;overflow:hidden}
.mb-head{padding:12px 16px;font-size:15px;font-weight:600;background:#181825;display:flex;align-items:center;justify-content:space-between}
.mb-head .mb-badge{background:#45475a;padding:2px 10px;border-radius:12px;font-size:12px;margin-left:8px}
.mb-row{display:flex;align-items:center;padding:10px 16px;border-bottom:1px solid #313244;transition:background .15s}
.mb-row:hover{background:#313244}
.mb-row.acked{opacity:.35}
.mb-row.acked .mb-text{text-decoration:line-through}
.mb-prio{font-weight:700;font-size:10px;text-transform:uppercase;padding:3px 8px;border-radius:4px;color:#fff;margin-right:12px;min-width:62px;text-align:center;flex-shrink:0}
.mb-p0{background:#2196F3}
.mb-p1{background:#FFC107;color:#333!important}
.mb-p2{background:#FF9800}
.mb-p3{background:#F44336;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.7}}
.mb-text{flex:1;font-size:13px;line-height:1.3}
.mb-time{font-size:11px;color:#6c7086;margin-left:12px;white-space:nowrap;flex-shrink:0}
.mb-btn{background:none;border:1px solid #585b70;color:#a6adc8;border-radius:4px;padding:4px 8px;cursor:pointer;font-size:12px;margin-left:8px;flex-shrink:0;text-decoration:none;display:inline-block}
.mb-btn:hover{background:#585b70;color:#cdd6f4;border-color:#cdd6f4}
.mb-empty{text-align:center;padding:40px 16px;color:#6c7086;font-size:14px}
.mb-empty-icon{font-size:32px;margin-bottom:8px;display:block}
.mb-footer{padding:10px 16px;text-align:center;border-top:1px solid #313244}
.mb-btn-clear{background:#45475a;border:none;color:#cdd6f4;border-radius:6px;padding:8px 20px;cursor:pointer;font-size:13px;text-decoration:none;display:inline-block}
.mb-btn-clear:hover{background:#585b70}
</style>';

        // ── Header ──
        $html .= '<div class="mb">';
        $html .= '<div class="mb-head">';
        $html .= '<span>&#128276; Meldungen <span class="mb-badge">' . $activeCount . '</span></span>';
        $html .= '</div>';

        // ── Meldungen ──
        if ($count === 0) {
            $html .= '<div class="mb-empty">';
            $html .= '<span class="mb-empty-icon">&#9989;</span>';
            $html .= 'Keine aktiven Meldungen';
            $html .= '</div>';
        } else {
            foreach ($messages as $msg) {
                $label = MessageStore::PRIORITY_LABELS[$msg['priority']] ?? 'Info';
                $prioClass = 'mb-p' . $msg['priority'];
                $time = date('H:i', $msg['timestamp']);
                $rowClass = $msg['acknowledged'] ? 'mb-row acked' : 'mb-row';

                $html .= '<div class="' . $rowClass . '">';
                $html .= '<span class="mb-prio ' . $prioClass . '">' . $label . '</span>';
                $html .= '<span class="mb-text">' . htmlspecialchars($msg['text']) . '</span>';
                $html .= '<span class="mb-time">' . $time . '</span>';

                if (!$msg['acknowledged']) {
                    $ackUrl = $hookBase . '?action=ack&id=' . urlencode($msg['id']);
                    $html .= '<a class="mb-btn" href="' . $ackUrl . '" title="Quittieren">&#10003;</a>';
                }

                $removeUrl = $hookBase . '?action=remove&id=' . urlencode($msg['id']);
                $html .= '<a class="mb-btn" href="' . $removeUrl . '" title="Entfernen">&#10005;</a>';
                $html .= '</div>';
            }
        }

        // ── Footer ──
        if ($showAckAll && $activeCount > 0) {
            $clearUrl = $hookBase . '?action=clearall';
            $html .= '<div class="mb-footer">';
            $html .= '<a class="mb-btn-clear" href="' . $clearUrl . '">&#10003; Alle quittieren</a>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    // ─── Tile-Visu (Kachel) ──────────────────────────────────────

    private function renderTileHTML(): string
    {
        $store = $this->loadMessages();
        $messages = $store->getAll();
        $count = $store->count();
        $activeCount = 0;
        foreach ($messages as $m) {
            if (!$m['acknowledged']) {
                $activeCount++;
            }
        }

        $instanceId = $this->InstanceID;

        $html = '<div style="padding:10px;font-family:sans-serif;color:#cdd6f4;height:100%;box-sizing:border-box;overflow-y:auto;background:#1e1e2e;">';

        // Header
        $html .= '<div style="font-size:14px;font-weight:bold;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;">';
        $html .= '<span>&#128276; Meldungen</span>';
        $html .= '<span style="background:#45475a;padding:2px 10px;border-radius:12px;font-size:12px;">' . $activeCount . '</span>';
        $html .= '</div>';

        if ($count === 0) {
            $html .= '<div style="text-align:center;padding:30px;color:#6c7086;">';
            $html .= '<div style="font-size:28px;margin-bottom:6px;">&#9989;</div>';
            $html .= 'Keine Meldungen';
            $html .= '</div>';
        } else {
            foreach (array_slice($messages, 0, 10) as $msg) {
                $color = MessageStore::PRIORITY_COLORS[$msg['priority']] ?? '#2196F3';
                $label = MessageStore::PRIORITY_LABELS[$msg['priority']] ?? 'Info';
                $time = date('H:i', $msg['timestamp']);
                $acked = $msg['acknowledged'];
                $opacity = $acked ? 'opacity:0.35;' : '';
                $strike = $acked ? 'text-decoration:line-through;' : '';
                $pulse = ($msg['priority'] === 3 && !$acked) ? 'animation:pulse 2s infinite;' : '';

                $html .= '<div onclick="requestAction(' . $instanceId . ', \'AcknowledgeMessage\', \'' . $msg['id'] . '\')" ';
                $html .= 'style="' . $opacity . 'padding:6px 0;border-bottom:1px solid #313244;display:flex;align-items:center;gap:8px;cursor:pointer;">';
                $html .= '<span style="color:' . $color . ';font-size:10px;font-weight:700;min-width:56px;text-align:center;' . $pulse . '">' . strtoupper($label) . '</span>';
                $html .= '<span style="flex:1;font-size:12px;line-height:1.3;' . $strike . '">' . htmlspecialchars($msg['text']) . '</span>';
                $html .= '<span style="font-size:10px;color:#6c7086;">' . $time . '</span>';
                $html .= '</div>';
            }

            if ($count > 10) {
                $html .= '<div style="text-align:center;font-size:11px;color:#6c7086;padding-top:6px;">... und ' . ($count - 10) . ' weitere</div>';
            }

            if ($this->ReadPropertyBoolean('ShowAcknowledgeAll') && $activeCount > 0) {
                $html .= '<div onclick="requestAction(' . $instanceId . ', \'AcknowledgeAll\', true)" ';
                $html .= 'style="text-align:center;padding:10px;margin-top:8px;cursor:pointer;background:#45475a;border-radius:6px;font-size:12px;">';
                $html .= '&#10003; Alle quittieren</div>';
            }
        }

        $html .= '</div>';

        // Pulse-Animation für Alarm
        $html .= '<style>@keyframes pulse{0%,100%{opacity:1}50%{opacity:.7}}</style>';

        return $html;
    }

    private function renderTileDataJSON(MessageStore $store): string
    {
        return json_encode([
            'count'    => $store->count(),
            'messages' => $store->getAll(),
        ], JSON_UNESCAPED_UNICODE);
    }

    private function removeOldestLowPriority(MessageStore $store): void
    {
        $all = $store->getAll();
        if (empty($all)) {
            return;
        }
        usort($all, function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] - $b['priority'];
            }
            return $a['timestamp'] - $b['timestamp'];
        });
        $store->remove($all[0]['id']);
    }

    // ─── Webhook-Verwaltung ──────────────────────────────────────

    private function RegisterHookIfNeeded(string $hook): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_GUID);
        if (count($ids) === 0) {
            return;
        }

        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        foreach ($hooks as $h) {
            if ($h['Hook'] === $hook && $h['TargetID'] === $this->InstanceID) {
                return; // bereits registriert
            }
        }

        // Alte Einträge für diesen Hook entfernen
        $hooks = array_filter($hooks, function ($h) use ($hook) {
            return $h['Hook'] !== $hook;
        });

        $hooks[] = ['Hook' => $hook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode(array_values($hooks)));
        IPS_ApplyChanges($ids[0]);
    }

    private function UnregisterHook(string $hook): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_GUID);
        if (count($ids) === 0) {
            return;
        }

        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        $hooks = array_filter($hooks, function ($h) use ($hook) {
            return $h['Hook'] !== $hook;
        });

        IPS_SetProperty($ids[0], 'Hooks', json_encode(array_values($hooks)));
        IPS_ApplyChanges($ids[0]);
    }
}
