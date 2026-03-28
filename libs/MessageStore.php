<?php

declare(strict_types=1);

/**
 * MessageStore – Datenhaltung & Business-Logik für Meldungen.
 *
 * Verwaltet ein Array von Message-Objekten (assoziative Arrays)
 * und bietet CRUD-Operationen, Filterung nach Priorität
 * sowie Expired-Cleanup.
 */
class MessageStore
{
    /** @var array<string, array> Meldungen, indexiert nach ID */
    private array $messages = [];

    // ─── Prioritäts-Konstanten ───────────────────────────────────
    public const PRIORITY_INFO    = 0;
    public const PRIORITY_NOTICE  = 1;
    public const PRIORITY_WARNING = 2;
    public const PRIORITY_ALARM   = 3;

    public const PRIORITY_LABELS = [
        self::PRIORITY_INFO    => 'Info',
        self::PRIORITY_NOTICE  => 'Hinweis',
        self::PRIORITY_WARNING => 'Warnung',
        self::PRIORITY_ALARM   => 'Alarm',
    ];

    public const PRIORITY_COLORS = [
        self::PRIORITY_INFO    => '#2196F3',
        self::PRIORITY_NOTICE  => '#FFC107',
        self::PRIORITY_WARNING => '#FF9800',
        self::PRIORITY_ALARM   => '#F44336',
    ];

    // ─── CRUD ────────────────────────────────────────────────────

    /**
     * Neue Meldung hinzufügen.
     *
     * @param string   $text     Meldungstext
     * @param int      $priority 0-3
     * @param string   $icon     Icon-Name (z.B. "Window", "Alert")
     * @param int      $ttl      Time-to-Live in Sekunden, 0 = kein Ablauf
     * @param int|null $sourceVariable  Quell-Variablen-ID (optional)
     *
     * @return string  ID der neuen Meldung
     */
    public function add(
        string $text,
        int $priority = self::PRIORITY_INFO,
        string $icon = 'Information',
        int $ttl = 0,
        ?int $sourceVariable = null
    ): string {
        $id = $this->generateId();
        $now = time();

        $this->messages[$id] = [
            'id'              => $id,
            'text'            => $text,
            'priority'        => max(0, min(3, $priority)),
            'icon'            => $icon,
            'timestamp'       => $now,
            'expires'         => ($ttl > 0) ? ($now + $ttl) : null,
            'acknowledged'    => false,
            'acknowledgedAt'  => null,
            'sourceVariable'  => $sourceVariable,
            'sourceModule'    => null,
        ];

        return $id;
    }

    /**
     * Meldung anhand der ID entfernen.
     */
    public function remove(string $id): bool
    {
        if (!isset($this->messages[$id])) {
            return false;
        }
        unset($this->messages[$id]);
        return true;
    }

    /**
     * Meldung als quittiert markieren.
     */
    public function acknowledge(string $id): bool
    {
        if (!isset($this->messages[$id])) {
            return false;
        }
        $this->messages[$id]['acknowledged'] = true;
        $this->messages[$id]['acknowledgedAt'] = time();
        return true;
    }

    // ─── Abfragen ────────────────────────────────────────────────

    /**
     * Alle Meldungen zurückgeben (sortiert nach Priorität desc, dann Zeitstempel desc).
     *
     * @return array<int, array>
     */
    public function getAll(): array
    {
        $list = array_values($this->messages);
        usort($list, function (array $a, array $b): int {
            // Höchste Priorität zuerst, bei gleicher Priorität neueste zuerst
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] - $a['priority'];
            }
            return $b['timestamp'] - $a['timestamp'];
        });
        return $list;
    }

    /**
     * Meldungen einer bestimmten Priorität.
     *
     * @return array<int, array>
     */
    public function getByPriority(int $priority): array
    {
        return array_values(array_filter(
            $this->messages,
            fn(array $msg): bool => $msg['priority'] === $priority
        ));
    }

    /**
     * Alle abgelaufenen Meldungen.
     *
     * @return array<int, array>
     */
    public function getExpired(): array
    {
        $now = time();
        return array_values(array_filter(
            $this->messages,
            fn(array $msg): bool => $msg['expires'] !== null && $msg['expires'] <= $now
        ));
    }

    /**
     * Meldung anhand der Quell-Variablen-ID suchen.
     *
     * @return array|null
     */
    public function findBySourceVariable(int $variableId): ?array
    {
        foreach ($this->messages as $msg) {
            if ($msg['sourceVariable'] === $variableId) {
                return $msg;
            }
        }
        return null;
    }

    // ─── Aufräumen ───────────────────────────────────────────────

    /**
     * Alle abgelaufenen Meldungen entfernen.
     *
     * @return int  Anzahl entfernter Meldungen
     */
    public function removeExpired(): int
    {
        $expired = $this->getExpired();
        foreach ($expired as $msg) {
            unset($this->messages[$msg['id']]);
        }
        return count($expired);
    }

    /**
     * Alle Meldungen löschen.
     */
    public function clearAll(): void
    {
        $this->messages = [];
    }

    /**
     * Alle Meldungen einer bestimmten Priorität löschen.
     *
     * @return int  Anzahl entfernter Meldungen
     */
    public function clearByPriority(int $priority): int
    {
        $count = 0;
        foreach ($this->messages as $id => $msg) {
            if ($msg['priority'] === $priority) {
                unset($this->messages[$id]);
                $count++;
            }
        }
        return $count;
    }

    // ─── Zähler ──────────────────────────────────────────────────

    public function count(): int
    {
        return count($this->messages);
    }

    public function countByPriority(int $priority): int
    {
        return count($this->getByPriority($priority));
    }

    // ─── Serialisierung ──────────────────────────────────────────

    /**
     * Alle Meldungen als JSON-String.
     */
    public function toJSON(): string
    {
        return json_encode(array_values($this->messages), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Meldungen aus JSON-String laden (ersetzt vorhandene).
     */
    public function fromJSON(string $json): void
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->messages = [];
            return;
        }

        $this->messages = [];
        foreach ($data as $msg) {
            if (isset($msg['id'])) {
                $this->messages[$msg['id']] = $msg;
            }
        }
    }

    // ─── Hilfsfunktionen ─────────────────────────────────────────

    /**
     * Eindeutige 8-stellige ID generieren.
     */
    private function generateId(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
