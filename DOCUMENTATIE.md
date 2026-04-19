# VTC Training Planner — functionele documentatie

WordPress-plugin voor **trainingsroosters** (blauwdruk + optionele uitzonderingsweken), **zaal/veld-niet-beschikbaar** (inhuur) en **Nevobo-programma** (wedstrijden) in het weekoverzicht. Er is **geen automatische planner**: jij zet trainingen handmatig neer (visueel of via lijstweergave).

---

## Installatie en rechten

1. Map `vtc-training-planner` in `wp-content/plugins/` plaatsen (of vanaf Git klonen).
2. Plugin activeren in **Plugins**. Bij activatie worden database-tabellen aangemaakt (`dbDelta`) en een standaard-blauwdruk aangemaakt als die nog ontbreekt.
3. Alleen gebruikers met **`manage_options`** (beheerders) zien het menu **Training** en mogen instellingen, stamdata en roosters wijzigen. REST-routes onder `/wp-json/vtc-tp/v1/admin/...` gebruiken dezelfde controle.

---

## Hoofdmenu (wp-admin)

Onder **Training**:

| Pagina | Doel |
|--------|------|
| **Instellingen** | Nevobo RSS-cache (seconden), scope wedstrijden in weekoverzicht. Clubcode staat bij Stamdata. |
| **Blauwdrukken** | Meerdere blauwdrukken (basis, afwijkende weken-patroon); koppeling aan stamdata per blauwdruk. |
| **Stamdata** | Vereniging (`vtc_tp_club`), teams, locaties en velden (venues). Zelfde denkmodel als de Team-app. |
| **Rooster (visueel)** | Drag-and-drop planner (blauwdruk of gekozen ISO-week), conceptversies, publiceren. |
| **Rooster (lijst)** | Lijstweergave van het rooster. |
| **Uitzonderingsweken** | Beheer van weken die afwijken van de blauwdruk. |
| **Weekoverzicht** | Voorbeeld van dezelfde data als op de site (incl. Nevobo-wedstrijden volgens instellingen). |

---

## Stamdata (kort)

- **Vereniging**: o.a. clubnaam en **Nevobo clubcode** (klein, zonder spaties; gebruikt voor `programma.rss`).
- **Teams**: weergavenaam, Nevobo team-type, trainings per week, min/max minuten, sorteervolgorde.
- **Locaties en velden**: per locatie meerdere velden; type zaal/buiten; optionele Nevobo-zaal- of veld-slug voor koppeling met wedstrijdfeed.

Knoppen voor **voorbeeld laden** en **teams uit Team-app** vullen stamdata vanuit vaste/snapshot-logica (alleen nuttig binnen VTC-omgeving).

---

## Rooster: blauwdruk, concept en live

- **Blauwdruk** = het terugkerende weekpatroon (ma–zo, per veld tijdlijn). Er kunnen **meerdere** blauwdrukrecords bestaan; de planner en stamdata kunnen per context een **actieve blauwdruk** (`blueprint_id`) gebruiken.
- **Conceptversie**: per blauwdruk bestaan **versies** (concept + één gepubliceerde live-versie). In de visuele planner kies je welke conceptversie je bewerkt; **Publiceren** zet de gekozen conceptversie live en kopieert slots naar `slot_published`.
- Wijzigingen in de visuele planner gaan naar het **concept** (`slot_draft`) van de gekozen versie. **Publiceren** kopieert naar het **gepubliceerde** rooster (`slot_published`) voor die blauwdruk/versie.
- **Uitzonderingsweek**: voor één ISO-week (`YYYY-Www`) kun je een apart rooster vastleggen. Als er een uitzondering bestaat, geldt die voor die week in plaats van de blauwdruk (gepubliceerd/concept volgens dezelfde logica als in code).
- **Afwijkende week (zelfde blauwdruk)**: optioneel patroon per ISO-week **zonder** aparte uitzonderingstabel (afwijkende blauwdruk-week); zie planner- en REST-logica.

### Visuele planner — modi

- **Teamrooster**: sleep teams vanuit de zijbalk naar een baan, of **klik op een lege baan** voor een **compact teamkiezervenster** (standaard teams met ruimte t.o.v. *trainings per week*; **Toon alle teams** voor de volledige lijst). Duur en positie met **sleep-handles**; blok **tussen velden** slepen: tijdens het slepen springt het blok mee naar de baan onder de cursor tot je **loslaat** (intern: `document`-pointerlisteners i.p.v. capture op het blok, zodat slepen na veldwissel doorloopt). Dubbelklik of × om te verwijderen waar toegestaan. Teamkiezer sluiten: **Escape** of klik buiten het venster.
- **Zaal/veld (inhuur)**: tekent **niet-beschikbare** periodes (geen teamblokken); handig voor huur/zaalblokkades.

### Week-navigatie

- **Blauwdruk**-weergave: referentiepatroon; keuze **blauwdruk** en **versie (bewerken)** waar van toepassing.
- **Week**-weergave: kies ISO-week; zonder uitzondering is het patroon read-only uit blauwdruk; met uitzondering bewerk je alleen die week.

---

## Frontend: shortcode, blok en REST

### Shortcode

```
[vtc_training_week]
[vtc_training_week week="2026-W15"]
```

Toont een **lijst** met trainingen en (volgens instelling) wedstrijden voor de opgegeven of de **huidige** ISO-week (site-tijdzone).

### Gutenberg-blok

Blok **`vtc-training-planner/week`** met optioneel attribuut `week` (zelfde ISO-formaat).

### Publieke REST (geen login nodig)

- `GET /wp-json/vtc-tp/v1/week/{YYYY-Www}`  
  JSON met `iso_week`, `used_exceptions`, `events` (zelfde samenvoeglogica als shortcode).

---

## Instellingen (options)

| Option | Standaard | Betekenis |
|--------|-----------|-----------|
| `vtc_tp_cache_ttl` | `1800` | Nevobo RSS-cache in seconden (minimaal 60 in code). |
| `vtc_tp_matches_scope` | `home_halls` | `home_halls`: alleen wedstrijden waar locatie aansluit op stamdata-eigen zalen; `all`: alle clubwedstrijden in de week. |
| `vtc_tp_nevobo_code` | `''` | Clubcode (ook gekoppeld aan stamdata-club rij 1 na migratie). |
| `vtc_tp_db_version` | — | Interne schemaversie. |

---

## Nevobo

- Data: **`https://api.nevobo.nl/export/vereniging/{code}/programma.rss`**
- Resultaat wordt geparsed en gefilterd op de gekozen week; combinatie met trainingen gebeurt in `VTC_TP_Schedule::get_merged_week()`.

---

## Database (overzicht)

Alle tabellen hebben het voorvoegsel `wp_` (of jouw `$table_prefix`).

| Tabel | Rol |
|-------|-----|
| `vtc_tp_club` | Vereniging + Nevobo-code |
| `vtc_tp_blueprint` | Blauwdruk-record (o.a. welke versie concept/live) |
| `vtc_tp_blueprint_version` | Versies per blauwdruk (concept / gepubliceerd) |
| `vtc_tp_deviation_week` | Afwijkende week zonder uitzonderingstabel (optioneel) |
| `vtc_tp_team` | Teams gekoppeld aan blauwdruk |
| `vtc_tp_location` / `vtc_tp_venue` | Locaties en velden |
| `vtc_tp_venue_unavail` | Niet-beschikbaar per veld (dag + tijd) |
| `vtc_tp_slot_draft` / `vtc_tp_slot_published` | Trainingsslots concept vs live |
| `vtc_tp_exception_week` / `vtc_tp_exception_slot` | Uitzonderingsweken en hun slots |

Dagindex in roosterdata: **0 = maandag … 6 = zondag** (Team-app-compatibel).

---

## Admin REST (`vtc-tp/v1/admin/...`)

Alle routes vereisen ingelogde gebruiker met `manage_options`. Basis-URL: `/wp-json/vtc-tp/v1/admin/`.

| Methode | Route | Functie |
|---------|-------|---------|
| GET | `planner` | Stamdata + slots voor visuele planner (blauwdruk); query o.a. `blueprint_id` |
| GET | `planner-week?iso_week=YYYY-Www` | Plannerdata voor specifieke week; query o.a. `blueprint_id` |
| GET / POST | `blueprints` | Lijst blauwdrukken / aanmaken (o.a. afwijkende blauwdruk) |
| POST | `blueprint-versions` | Nieuwe conceptversie vanaf live |
| POST | `blueprint-editing-version` | Actieve bewerkversie zetten |
| POST | `deviation-weeks` | Afwijkende week (zelfde blauwdruk) aanmaken |
| DELETE | `deviation-weeks/{id}` | Afwijkende week verwijderen |
| POST | `slots` | Nieuw trainingsslot |
| PATCH/DELETE | `slots/{id}` | Slot wijzigen/verwijderen |
| POST | `publish` | Concept → gepubliceerd |
| POST | `unavailability` | Inhuur-blok |
| PATCH/DELETE | `unavailability/{id}` | Inhuur-blok wijzigen/verwijderen |
| POST | `exception-weeks` | Uitzonderingsweek aanmaken |
| DELETE | `exception-weeks/{id}` | Uitzonderingsweek verwijderen |
| POST | `exception-slots` | Slot in uitzonderingsweek |
| PATCH/DELETE | `exception-slots/{id}` | Uitzonderingsslot wijzigen/verwijderen |

De visuele admin-UI gebruikt deze endpoints via JavaScript (`assets/planner-admin.js`).

---

## Technische notities

- **PHP** ≥ 7.4, **WordPress** ≥ 6.0 (plugin header).
- Versieconstant in code: `VTC_TP_VERSION` (cache-bust voor `planner-admin.js` / `planner-admin.css`); kan afwijken van de header-`Version:`.
- Bestanden: `includes/` (DB, schedule, Nevobo, REST), `admin/` (schermen), `public/` (shortcode, blok, publieke REST), `assets/` (CSS/JS).
- Teamblokken op de tijdas: `box-sizing: border-box` op blokken zodat de **visuele breedte** overeenkomt met `start_time` / `end_time` (geen extra px door border/padding).

---

## Repository

Broncode voor deze plugin: [VTC-Woerden/wp-plugin-training-planner](https://github.com/VTC-Woerden/wp-plugin-training-planner) op GitHub.

> **Let op:** de aparte **Wedstrijd planner** staat in [wp-plugin-wedstrijd-planner](https://github.com/VTC-Woerden/wp-plugin-wedstrijd-planner); zie daarin `DOCUMENTATIE.md`.
