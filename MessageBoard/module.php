<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MessageStore.php';

/**
 * MessageBoard – Zentrale Meldungsanzeige für IP-Symcon 7.x
 *
 * Verwaltet Meldungen mit Prioritäten, Quittierung und Verfallszeit.
 * Bietet eine Tile-Visualisierung für das WebFront sowie eine
 * öffentliche API (Präfix: MSGBOARD_).
 */
class MessageBoard extends IPSModule
{
    // ═════════════════════════════════════════════════════════════
    //  Modul-Lifecycle
    // ═════════════════════════════════════════════════════════════

    public function Create()
    {
        parent::Create();

        // ── Properties (Konfiguration, über form.json editierbar) ──
        $this->RegisterPropertyInteger('MaxMessages', 50);
        $this->RegisterPropertyInteger('CleanupInterval', 60);
        $this->RegisterPropertyString('WatchedVariables', '[]');
        $this->RegisterPropertyBoolean('ShowAcknowledgeAll', true);
        $this->RegisterPropertyInteger('SortOrder', 0); // 0 = neueste zuerst

        // ── Attribute (interne Datenhaltung) ──
        $this->RegisterAttributeString('Messages', '[]');

        // ── Timer ──
        $this->RegisterTimer('CleanupExpired', 0, 'MSGBOARD_CleanupExpired($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Alte Nachrichten-Registrierungen aufheben (sauberer Neustart)
        // Hinweis: In Phase 2 wird hier die Variable-Überwachung erweitert
        $watched = json_decode($this->ReadPropertyString('WatchedVariables'), true);
        if (is_array($watched)) {
            foreach ($watched as $entry) {
                if (isset($entry['VariableID']) && $entry['VariableID'] > 0) {
                    $this->RegisterMessage($entry['VariableID'], VM_UPDATE);
                }
            }
        }

        // Cleanup-Timer setzen
        $interval = $this->ReadPropertyInteger('CleanupInterval');
        $this->SetTimerInterval('CleanupExpired', $interval * 1000);

        // Instanz-Status setzen
        $this->SetStatus(102); // IS_ACTIVE
    }

    // ═════════════════════════════════════════════════════════════
    //  Event-Eingang (Phase 2 – Platzhalter)
    // ═════════════════════════════════════════════════════════════

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE) {
            $this->SendDebug('MessageSink', sprintf(
                'Variable %d aktualisiert: %s',
                $SenderID,
                strval($Data[0])
            ), 0);
            // Phase 2: $this->handleVariableUpdate($SenderID, $Data[0]);
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  RequestAction (Tile-Klick Handler)
    // ═════════════════════════════════════════════════════════════

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'AcknowledgeMessage':
                $this->AcknowledgeMessage($Value);
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

    /**
     * Neue Meldung hinzufügen.
     *
     * @param string $Text     Meldungstext
     * @param int    $Priority 0=Info, 1=Hinweis, 2=Warnung, 3=Alarm
     * @param string $Icon     Icon-Name (z.B. "Window", "Alert", "Information")
     * @param int    $TTL      Time-to-Live in Sekunden (0 = kein Ablauf)
     *
     * @return string  ID der neuen Meldung
     */
    public function AddMessage(string $Text, int $Priority = 0, string $Icon = 'Information', int $TTL = 0): string
    {
        $store = $this->loadMessages();

        // Maximale Anzahl prüfen
        $max = $this->ReadPropertyInteger('MaxMessages');
        if ($store->count() >= $max) {
            $this->SendDebug('AddMessage', 'Max. Meldungsanzahl erreicht (' . $max . ')', 0);
            // Älteste Meldung mit niedrigster Priorität entfernen
            $this->removeOldestLowPriority($store);
        }

        $id = $store->add($Text, $Priority, $Icon, $TTL);
        $this->persistMessages($store);
        $this->updateVisualization();

        $this->SendDebug('AddMessage', sprintf(
            'Meldung hinzugefügt: [%s] %s (Prio: %d, TTL: %ds)',
            $id, $Text, $Priority, $TTL
        ), 0);

        return $id;
    }

    /**
     * Meldung anhand der ID entfernen.
     */
    public function RemoveMessage(string $MessageID): bool
    {
        $store = $this->loadMessages();
        $result = $store->remove($MessageID);

        if ($result) {
            $this->persistMessages($store);
            $this->updateVisualization();
            $this->SendDebug('RemoveMessage', 'Meldung entfernt: ' . $MessageID, 0);
        }

        return $result;
    }

    /**
     * Meldung als quittiert markieren.
     */
    public function AcknowledgeMessage(string $MessageID): bool
    {
        $store = $this->loadMessages();
        $result = $store->acknowledge($MessageID);

        if ($result) {
            $this->persistMessages($store);
            $this->updateVisualization();
            $this->SendDebug('AcknowledgeMessage', 'Meldung quittiert: ' . $MessageID, 0);
        }

        return $result;
    }

    /**
     * Alle Meldungen löschen.
     */
    public function ClearAll(): void
    {
        $store = $this->loadMessages();
        $store->clearAll();
        $this->persistMessages($store);
        $this->updateVisualization();
        $this->SendDebug('ClearAll', 'Alle Meldungen gelöscht', 0);
    }

    /**
     * Alle Meldungen einer bestimmten Priorität löschen.
     */
    public function ClearByPriority(int $Priority): void
    {
        $store = $this->loadMessages();
        $count = $store->clearByPriority($Priority);
        $this->persistMessages($store);
        $this->updateVisualization();
        $this->SendDebug('ClearByPriority', sprintf(
            '%d Meldung(en) mit Priorität %d gelöscht',
            $count, $Priority
        ), 0);
    }

    /**
     * Alle Meldungen als Array zurückgeben.
     *
     * @return array
     */
    public function GetMessages(): array
    {
        $store = $this->loadMessages();
        return $store->getAll();
    }

    /**
     * Gesamtanzahl der Meldungen.
     */
    public function GetMessageCount(): int
    {
        $store = $this->loadMessages();
        return $store->count();
    }

    /**
     * Anzahl der Meldungen einer bestimmten Priorität.
     */
    public function GetMessageCountByPriority(int $Priority): int
    {
        $store = $this->loadMessages();
        return $store->countByPriority($Priority);
    }

    // ═════════════════════════════════════════════════════════════
    //  Timer-Callback: Abgelaufene Meldungen entfernen
    // ═════════════════════════════════════════════════════════════

    /**
     * Wird vom CleanupExpired-Timer aufgerufen.
     */
    public function CleanupExpired(): void
    {
        $store = $this->loadMessages();
        $removed = $store->removeExpired();

        if ($removed > 0) {
            $this->persistMessages($store);
            $this->updateVisualization();
            $this->SendDebug('CleanupExpired', $removed . ' abgelaufene Meldung(en) entfernt', 0);
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  Tile-Visualisierung (Phase 3 – Platzhalter)
    // ═════════════════════════════════════════════════════════════

    /**
     * HTML für die Tile-Visualisierung zurückgeben.
     * Vollständige Implementierung in Phase 3.
     */
    public function GetVisualizationTile(): string
    {
        return $this->renderTileHTML();
    }

    // ═════════════════════════════════════════════════════════════
    //  Private Hilfsmethoden
    // ═════════════════════════════════════════════════════════════

    /**
     * Meldungen aus dem Attribut laden.
     */
    private function loadMessages(): MessageStore
    {
        $store = new MessageStore();
        $json = $this->ReadAttributeString('Messages');
        $store->fromJSON($json);
        return $store;
    }

    /**
     * Meldungen im Attribut persistieren.
     */
    private function persistMessages(MessageStore $store): void
    {
        $this->WriteAttributeString('Messages', $store->toJSON());
    }

    /**
     * Tile-Update an das WebFront pushen.
     */
    private function updateVisualization(): void
    {
        $this->UpdateVisualizationValue($this->renderTileDataJSON());
    }

    /**
     * Minimale Tile-HTML (Platzhalter – Phase 3 bringt das volle Design).
     */
    private function renderTileHTML(): string
    {
        $store = $this->loadMessages();
        $messages = $store->getAll();
        $count = $store->count();

        $html = '<div style="padding:8px;font-family:sans-serif;color:#fff;">';
        $html .= '<div style="font-size:14px;font-weight:bold;margin-bottom:6px;">';
        $html .= '🔔 Meldungen <span style="background:#555;padding:2px 8px;border-radius:10px;font-size:12px;">' . $count . '</span>';
        $html .= '</div>';

        if ($count === 0) {
            $html .= '<div style="text-align:center;padding:20px;opacity:0.5;">Keine Meldungen</div>';
        } else {
            foreach (array_slice($messages, 0, 10) as $msg) {
                $color = MessageStore::PRIORITY_COLORS[$msg['priority']] ?? '#2196F3';
                $label = MessageStore::PRIORITY_LABELS[$msg['priority']] ?? 'Info';
                $time = date('H:i', $msg['timestamp']);
                $ack = $msg['acknowledged'] ? ' style="opacity:0.5;text-decoration:line-through;"' : '';

                $html .= '<div' . $ack . ' style="padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.1);display:flex;align-items:center;gap:6px;">';
                $html .= '<span style="color:' . $color . ';font-size:10px;font-weight:bold;min-width:60px;">' . strtoupper($label) . '</span>';
                $html .= '<span style="flex:1;font-size:12px;">' . htmlspecialchars($msg['text']) . '</span>';
                $html .= '<span style="font-size:10px;opacity:0.6;">' . $time . '</span>';
                $html .= '</div>';
            }

            if ($count > 10) {
                $html .= '<div style="text-align:center;font-size:11px;opacity:0.5;padding-top:4px;">... und ' . ($count - 10) . ' weitere</div>';
            }
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * JSON-Daten für UpdateVisualizationValue.
     */
    private function renderTileDataJSON(): string
    {
        $store = $this->loadMessages();
        return json_encode([
            'count'    => $store->count(),
            'messages' => $store->getAll(),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Älteste Meldung mit niedrigster Priorität entfernen (FIFO bei Überlauf).
     */
    private function removeOldestLowPriority(MessageStore $store): void
    {
        $all = $store->getAll();
        if (empty($all)) {
            return;
        }

        // Sortiere: niedrigste Priorität zuerst, dann älteste zuerst
        usort($all, function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] - $b['priority'];
            }
            return $a['timestamp'] - $b['timestamp'];
        });

        $store->remove($all[0]['id']);
    }
}
