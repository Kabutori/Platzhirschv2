# API/MCP-Auslagerung – vorbereitet, noch nicht aktiviert

Die bisherigen Pakete werden unverändert aus dem Module-Host ausgegliedert:

| Ziel-Repository unter Kabutori | Paket | Quellpfad im neuen Repository |
| --- | --- | --- |
| platzhirsch-module-api | platzhirsch/api | . |
| platzhirsch-module-mcp | platzhirsch/mcp | . |
| platzhirsch-module-mcp | @platzhirsch/mcp | node |

`python tools/modules/split_api_mcp.py --output <neuer-Ordner>` erzeugt die vollständigen Repository-Inhalte aus den geprüften Quellen in `modules.lock.json`. Enthalten sind Paketmetadaten, Archiv-Erzeugung, Prüfsummen und GitHub-Prüf-/Release-Workflows. Der MCP-Workflow führt zusätzlich die fünf Transporttests aus. Versionen und Paketnamen bleiben erhalten.

Lokal geprüft: alle 16 Paketdateien in den drei erzeugten Archiven stimmen mit den bisherigen Anwendungsquellen überein; fünf MCP-Tests erfolgreich.

## Noch notwendige Aktivierung

1. Zwei private GitHub-Repositories mit den obigen Namen und initialem README anlegen oder für die bestehende GitHub-Verbindung freigeben. Bei der Prüfung waren beide nicht erreichbar (404). Die Browser-Anmeldung wurde abgebrochen.
2. Erzeugte Quellen in die Ziel-Repositories übernehmen, CI bestehen lassen und auf main mergen.
3. `modules.lock.json` auf die tatsächlichen neuen Commits umstellen: die drei Pakete aus dem Host-Eintrag entfernen und zwei neue Repository-Einträge mit den oben angegebenen Quellpfaden hinzufügen. Paketdatei-Hashes bleiben unverändert.
4. `tools/modules/repositories.json` um die beiden neuen Zuordnungen erweitern. Host-Metadaten und aktuelle Host-Quellen bereinigen; bisher veröffentlichte Host-Versionen erhalten.
5. Registry-Zusammensetzung, unabhängige Archivinstallation, API-/MCP-Tests, vollständige Anwendungs-CI und Windows-Paket prüfen. Anschließend Hauptprojekt mergen und Restpunkt 4 als erledigt markieren.

Die aktive Zusammensetzung bleibt bis dahin beim geprüften bisherigen Commit. Es werden keine erfundenen Commit-IDs oder Verweise auf leere Repositories aktiviert. Remote-MCP über HTTP/OAuth gehört nicht zu dieser Repository-Aufteilung.
