# Glossar

## Organisation (nicht „Verein")

**Der Betreiber dieser Software heisst durchgehend *Organisation*.** Vorgabe: „benenne Verein in Organisation um … die SW soll auch von Part-145 betrieben
werden."

Das ist keine Kosmetik, sondern die Reichweite: CLAUDE.md sieht von Anfang an
vor, dass die Anwendung „vom kleinen Verein (nur Lager) bis zum kleinen
Part-145-Betrieb" skaliert. Ein Betrieb mit Genehmigung ist kein Verein, und
eine Oberfläche, die ihn so anspricht, wirkt wie für jemand anderen gebaut.

| bis dahin | jetzt |
|---|---|
| `CLUB_NAME`, `CLUB_TIMEZONE` | `ORGANISATION_NAME`, `ORGANISATION_TIMEZONE` |
| `aeronance.club.*` | `aeronance.organisation.*` |
| „Verein" in Beschriftungen | „Organisation" |

**Wo „Verein" richtig bleibt:** als *Halterart* eines Luftfahrzeugs
(`fleet.halter.club`) — dort ist es eine Tatsache über den Eigentümer und keine
Annahme über den Betreiber. Ebenso in Fliesstexten der Dokumentation, wo ein
Verein das konkrete Beispiel ist („für einen Verein irrelevant, weil nur die
geflogenen Muster abgerufen werden").

**Eine Sachaussage, die mit Part-145 kippt** und deshalb hier steht: Das
Lagermodul geht davon aus, dass der Betreiber **keine Bauteile instand setzen
darf** — daraus folgen die Ausbau-Regel und der Reparaturweg. Für einen Betrieb
mit Komponentenberechtigung gilt das nicht. Die Naht dafür liegt bereits
(`RepairDestination::InHouse`, gebunden an ein Modul `component-repair`, siehe
[`LAGERMODUL.md`](LAGERMODUL.md) §15) — gebaut ist sie nicht.

Verbindliche Zuordnung deutscher Fachbegriffe zu den englischen Bezeichnern in
Code und Datenbank. **UI deutsch, Code/DB/Commits englisch** — dieses Dokument
ist die Brücke dazwischen und wird gepflegt, sobald ein neuer Fachbegriff
auftaucht.

Die Spalte *Bestand* nennt den Bezeichner aus dem Vorgängersystem, soweit es
einen gab — nur als Lesehilfe beim Nachschlagen in
[`ANALYSE.md`](ANALYSE.md), nicht als Vorgabe.

---

## Kern

| Deutsch (UI) | Bezeichner | Bestand | Anmerkung |
|---|---|---|---|
| Benutzer | `user` | `sy_users` | |
| Rolle | `role` | `sy_groups` | `spatie/laravel-permission` |
| Recht, Berechtigung | `permission` | `sy_access` | `spatie/laravel-permission` |
| Rechtegruppe (Anzeige) | `permission_group` | `access_group` | nur Gruppierung in der Oberfläche |
| Qualifikation | `qualification` | *(neu)* | **kein** Rollenkonzept — siehe E8 |
| Part-66-Lizenz | `part66_licence` | *(neu)* | personengebunden, systemweit |
| Pilot-Owner-Berechtigung | `pilot_owner_authorisation` | *(neu)* | **je Luftfahrzeug**, über AMP-Eintrag |
| Kenntnisnachweis | `proof_of_competence` | *(neu)* | Voraussetzung der PO-Berechtigung |
| Break-glass-Zugang | `break_glass` | `is_admin` | nur über die Konsole, siehe E2 |
| Aktivitätsprotokoll | `activity_log` | `sy_logs` | `spatie/laravel-activitylog`, append-only |
| Bescheinigungsinhalt | *(Snapshot-Spalten)* | *(neu)* | unveränderliche Kopie, siehe E7 |
| Modul | `module` | — | einzeln aktivierbar |
| Modul-Manifest | `manifest` | — | Name, Version, `requires`, `conflicts` |

## Lager

| Deutsch (UI) | Bezeichner | Bestand | Anmerkung |
|---|---|---|---|
| Lagerort | `storage_location` | `wh_locations` | |
| Lagerfach, Fach | `storage_compartment` | `wh_compartments` | |
| Sperrlager, Quarantäne | `quarantine_location` | *(neu)* | Lagerorttyp, Trennungspflicht |
| Lieferant | `supplier` | `wh_suppliers` | Stammdatum, keine Beschaffung (E6) |
| Bauteiltyp, Teilestamm | `part_type` | `wh_itemtypes` | trägt die Klassifizierung |
| Los, Charge | `stock_lot` | *(neu)* | **die rückverfolgbare Einheit** |
| Bestandsbewegung, Buchung | `stock_movement` | *(neu)* | append-only, ergibt den Bestand |
| Gegenbuchung | `reversal_movement` | *(neu)* | Korrektur statt Änderung |
| Einbuchen | `receive` | `action=add` | Wareneingang |
| Ausbuchen, Entnahme | `issue` | `removed` | |
| Sperrzettel | `quarantine_tag` | *(neu)* | Nummernkreis `YYYYMM-NNN` |
| Losnummer | `lot_number` | *(neu)* | Nummernkreis `YYYYMM-NNN` |
| Zustandsänderung | `lot_state_change` | *(neu)* | append-only, mit Snapshot bei Feststellungen |
| Klassifizierung | `classification` | *(neu)* | am Bauteiltyp, nach 145.A.42 |
| Vorgangsbezug | `work_order_reference` | *(neu)* | freier Text, **kein** FK (D4) |
| Luftfahrzeugbezug | `aircraft_reference` | *(neu)* | freier Text, **kein** FK (D4) |
| Menge | `quantity` | `amount` | |
| Einheit | `unit_of_measure` | `unit` | |
| Bestellnummer | `order_code` | `OC` | Artikelnummer beim Lieferanten |
| IPC-Teilenummer | `ipc_part_number` | `IPC_NO` | Illustrated Parts Catalogue |
| Seriennummer | `serial_number` | `serial` | |
| seriennummerngeführt | `serial_tracked` | `has_serial` | |
| Mindestbestand | `minimum_stock` | `min_amount` | |
| Maximalbestand | `maximum_stock` | `max_amount` | |
| Maximale Lagerzeit | `shelf_life_days` | `shelflife_days` | nur **kalendarisch** |
| Verfallsdatum | `expires_at` | `EOL` | |
| Einlagerungsdatum | `received_at` | `added` | |
| Inventurbericht | `inventory_report` | *(Stub)* | |

### Klassifizierung nach 145.A.42

| Deutsch | Bezeichner |
|---|---|
| brauchbar | `serviceable` |
| unbrauchbar | `unserviceable` |
| ausgemustert (nicht instandsetzbar) | `unsalvageable` |
| entsorgt | `disposed` |
| Standard Part | `standard_part` |
| Verbrauchsmaterial | `consumable_material` |

### Nachweise

| Deutsch | Bezeichner | Anmerkung |
|---|---|---|
| Form 1 | `form_one` | am **Los**, nicht am Einzelteil |
| Konformitätsbescheinigung | `certificate_of_conformity` | für Standard Parts |
| Lebenslaufakte (L-Akte) | `life_record` | Ziel der Form-1-Übergabe |

## Flotte, Arbeitskarten, Freigaben

*Noch nicht gebaut — Begriffe hier vorgemerkt, damit sie nicht auseinanderlaufen.*

| Deutsch (UI) | Bezeichner | Anmerkung |
|---|---|---|
| Vorgang | `work_order` | |
| Arbeitskarte | `task_card` | |
| Befund | `finding` | |
| Freigabe (CRS) | `release_to_service` | unveränderlich nach Erteilung |
| Instandhaltungsprogramm | `maintenance_programme` | AMP |
| Luftfahrzeug | `aircraft` | |
| Kennzeichen | `registration` | Format nicht hartkodieren |
| Komponente | `component` | |
| Luftfahrzeugmuster, Muster | `aircraft_type` | trägt Kennblatt und Musterbetreuung |
| Musterbetreuer | `type_support` | Freitext am Muster, z. B. „LTB Lindner" |
| verwaistes Muster | `without_type_support` | **ausdrückliches Kennzeichen**, nie aus „keine Quelle gefunden" abgeleitet |
| Lufttüchtigkeitsanweisung | `airworthiness_directive` | AD |

## Identity-Provider

| Deutsch | Bezeichner | Anmerkung |
|---|---|---|
| VF-Mitglied | `vf_member` | im Modulnamespace |
| VF-Benutzer-ID | `vf_uid` | |
| VF-Funktion | `vf_function` | relational, **nicht** als `;`-Liste |
| Mitgliedsstatus | `member_status` | |
| Zuordnung extern → Rolle | `role_mapping` | **im Kern**, nicht im Provider (E4) |

---

## Begriffe, die bewusst *nicht* verwendet werden

| Nicht verwenden | Stattdessen | Grund |
|---|---|---|
| `EOL` | `expires_at` | „End of Life" meint in der Luftfahrt die Lebensdauergrenze, nicht das Lagerverfallsdatum |
| `item` allein | `part_type` oder `stock_lot` | im Bestand vermischt; die Trennung ist fachlich zentral |
| `amount` | `quantity` | |
| `group` für Rollen | `role` | „Gruppe" ist im Identity-Kontext die *externe* Gruppe |
| `admin`-Flag | Rolle + Break-glass | siehe E2 |
| Lieferschein, Bestellung, Rechnung | — | Warenwirtschaft, ausdrücklich außerhalb (E6) |
