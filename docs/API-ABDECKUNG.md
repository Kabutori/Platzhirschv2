# API-Abdeckung je Modul

Alle vorhandenen API-Routen sind klassifiziert und durch `ApiContractsTest` gegen die tatsächlich installierten Controller geprüft. Externe Operationen verwenden die bestehenden Fachrechte. Zahlen sind Operationen (Methode + Pfad), keine Behauptung neuer Fachfunktionen. API-/MCP-Verwaltung selbst bleibt sitzungsgebunden.

| Modul | Extern lesen | Extern schreiben | Eigener Zugang / interaktiv |
| --- | ---: | ---: | ---: |
| platform | 9 | 13 | 1 |
| api | 0 | 0 | 0 |
| audit | 1 | 0 | 0 |
| billing | 12 | 18 | 1 |
| customer | 3 | 9 | 0 |
| identity | 6 | 15 | 18 |
| integration-odoo | 1 | 0 | 0 |
| mcp | 0 | 0 | 0 |
| notification | 1 | 1 | 0 |
| provisioning | 2 | 4 | 0 |
| release | 1 | 2 | 0 |
| reporting | 2 | 2 | 0 |
| reservation | 7 | 14 | 0 |
| support | 2 | 2 | 0 |
| weather | 1 | 1 | 0 |
| widget | 1 | 3 | 5 |

Details und Grenzen: [API-BETRIEB.md](API-BETRIEB.md). Odoo stellt ausschließlich seinen Platzhalterstatus bereit. Verträge liegen in jedem Modul unter `src/api.json`; Plattformverträge unter `app/app/Api/platform.json`.
