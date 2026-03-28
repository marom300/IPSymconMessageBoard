# MessageBoard – Meldungsanzeige für IP-Symcon 7.x

Zentrale Verwaltung und Anzeige von Meldungen im WebFront mit Tile-Visualisierung.

## Features

- **4 Prioritätsstufen:** Info, Hinweis, Warnung, Alarm
- **Quittierung:** Meldungen im WebFront bestätigen
- **Verfallszeit:** Optionale TTL pro Meldung
- **Tile-Visualisierung:** Moderne HTML-Kachel mit Icons, Farben und Badge-Zähler
- **Dual-API:** Funktionsaufrufe + Event-basierte Variablenüberwachung

## Installation

1. Im IP-Symcon **Module Control** folgende URL hinzufügen:
   `https://github.com/romanmartin/IPSymconMessageBoard.git`
2. Instanz hinzufügen: **MessageBoard**

## API

```php
// Meldung hinzufügen (Text, Priorität 0-3, Icon, TTL in Sekunden)
MSGBOARD_AddMessage(12345, "Fenster offen", 2, "Window", 0);

// Alle Meldungen abfragen
MSGBOARD_GetMessages(12345);

// Meldung quittieren
MSGBOARD_AcknowledgeMessage(12345, "a1b2c3d4");

// Meldung entfernen
MSGBOARD_RemoveMessage(12345, "a1b2c3d4");

// Alle löschen
MSGBOARD_ClearAll(12345);
```

## Prioritäten

| Stufe | Name | Farbe |
|-------|------|-------|
| 0 | Info | Blau |
| 1 | Hinweis | Gelb |
| 2 | Warnung | Orange |
| 3 | Alarm | Rot |

## Anforderungen

- IP-Symcon 7.0 oder höher

## Lizenz

CC BY-NC-SA 4.0
