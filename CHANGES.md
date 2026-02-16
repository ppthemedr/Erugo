# Wijzigingen: Upload Links API

## Probleem

Erugo miste een manier om via de API een upload-link aan te maken die:
- De bruikbare URL teruggaf in de API-response (niet alleen via email)
- Meerdere uploads toestond over een instelbare periode (bijv. 30 dagen)
- Niet verliep na een enkele upload

## Oplossing: Nieuw `/api/upload-links` endpoint

Een compleet nieuw systeem naast de bestaande reverse shares. Upload links zijn lichter (geen email vereist, geen recipient data) en ontworpen voor API-gebruik.

### API Endpoints

| Methode | Pad | Auth | Beschrijving |
|---------|-----|------|-------------|
| `POST` | `/api/upload-links` | JWT (auth) | Maak een nieuwe upload link |
| `GET` | `/api/upload-links` | JWT (auth) | Lijst al je upload links |
| `DELETE` | `/api/upload-links/{id}` | JWT (auth) | Verwijder een upload link |
| `GET` | `/api/upload-links/accept?token=xxx` | Publiek | Accepteer een upload link (geeft JWT voor gastgebruiker) |

### Parameters voor `POST /api/upload-links`

| Parameter | Type | Verplicht | Default | Beschrijving |
|-----------|------|-----------|---------|-------------|
| `name` | string | Ja | — | Naam van de upload link (bijv. "Klant X") |
| `expires_days` | integer (1-365) | Nee | 30 | Aantal dagen geldig |
| `max_uses` | integer (0+) | Nee | 0 | Max aantal uploads. `0` = onbeperkt |

### Response van `POST /api/upload-links`

```json
{
  "status": "success",
  "data": {
    "upload_link": {
      "id": 1,
      "user_id": 1,
      "name": "Klant X Upload",
      "max_uses": 0,
      "use_count": 0,
      "expires_at": "2026-03-18T08:27:28.000000Z",
      "active": true
    },
    "upload_url": "http://localhost:8787/?upload_token=abc123...",
    "token": "abc123..."
  }
}
```

### Voorbeeld API-gebruik

```bash
# 1. Login
curl -X POST http://localhost:8787/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"wachtwoord"}'

# 2. Maak een upload link (30 dagen, onbeperkt uploads)
curl -X POST http://localhost:8787/api/upload-links \
  -H "Authorization: Bearer {jouw_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Klant X Upload",
    "expires_days": 30,
    "max_uses": 0
  }'

# 3. Deel de upload_url uit de response met je klant
#    De klant kan die link de komende 30 dagen onbeperkt gebruiken

# 4. Bekijk al je upload links
curl http://localhost:8787/api/upload-links \
  -H "Authorization: Bearer {jouw_token}"

# 5. Verwijder een upload link
curl -X DELETE http://localhost:8787/api/upload-links/1 \
  -H "Authorization: Bearer {jouw_token}"
```

### Hoe het werkt (flow)

1. **Admin maakt upload link** via `POST /api/upload-links`
   - Er wordt een gastgebruiker aangemaakt (`is_guest=true`)
   - Er wordt een random 64-char token gegenereerd
   - De API retourneert de `upload_url` en het `token`

2. **Klant opent de `upload_url`** in de browser
   - Frontend detecteert `upload_token` in de URL
   - Roept `GET /api/upload-links/accept?token=xxx` aan
   - Ontvangt een JWT access token voor de gastgebruiker
   - Klant kan nu bestanden uploaden

3. **Na upload**
   - `use_count` wordt met 1 verhoogd
   - De share wordt gekoppeld aan de link-maker (niet de gast)
   - Bij onbeperkt (`max_uses=0`): gastgebruiker blijft bestaan, link werkt opnieuw
   - Bij limiet bereikt (`use_count >= max_uses`): link wordt gedeactiveerd

---

## Technische details per bestand

### 1. Database Migration

**Bestand**: `database/migrations/2026_02_16_120000_create_upload_links_table.php` (NIEUW)

Nieuwe tabel `upload_links`:

| Kolom | Type | Beschrijving |
|-------|------|-------------|
| `id` | bigint PK | Auto-increment |
| `user_id` | FK -> users | De maker van de link |
| `guest_user_id` | FK -> users (nullable) | De gastgebruiker die aan de link gekoppeld is |
| `name` | varchar(255) | Beschrijvende naam |
| `token` | varchar(64), unique | Opaque random token voor lookup |
| `max_uses` | integer, default 0 | Max uploads (0 = onbeperkt) |
| `use_count` | integer, default 0 | Huidige aantal voltooide uploads |
| `expires_at` | timestamp | Verloopdatum |
| `active` | boolean, default true | Of de link actief is |
| `created_at` | timestamp | Aanmaakdatum |
| `updated_at` | timestamp | Laatste wijziging |

### 2. UploadLink Model

**Bestand**: `app/Models/UploadLink.php` (NIEUW)

Methodes:
- **`isExpired()`**: Controleert of `expires_at` in het verleden ligt
- **`isActive()`**: `active && !isExpired() && !hasReachedUseLimit()`
- **`hasReachedUseLimit()`**: `max_uses > 0 && use_count >= max_uses`. Bij `max_uses = 0` retourneert het altijd `false`.
- **`incrementUseCount()`**: Verhoogt `use_count` met 1

Hidden fields: `token` en `guest_user_id` worden niet meegeserialiseerd in JSON responses (beveiliging).

### 3. UploadLinksController

**Bestand**: `app/Http/Controllers/UploadLinksController.php` (NIEUW)

4 methodes:

- **`create(Request)`**: Valideert input, maakt gastgebruiker aan, genereert 64-char token, maakt `UploadLink` record, retourneert `upload_url` en `token`.

- **`index()`**: Haalt alle upload links op voor de ingelogde gebruiker, inclusief berekend `is_active` veld.

- **`delete($id)`**: Verwijdert de upload link en de bijbehorende gastgebruiker (alleen als `is_guest=true`).

- **`accept(Request)`**: Publiek endpoint. Zoekt link op basis van token, controleert `isActive()` (met specifieke foutmelding voor expired/use-limit/deactivated), logt in als gastgebruiker, retourneert JWT.

### 4. Routes

**Bestand**: `routes/api.php` (GEWIJZIGD)

Toegevoegd:
```php
// Upload links [auth]
Route::group(['prefix' => 'upload-links', 'middleware' => ['auth']], function ($router) {
    Route::post('/', [UploadLinksController::class, 'create']);
    Route::get('/', [UploadLinksController::class, 'index']);
    Route::delete('/{id}', [UploadLinksController::class, 'delete']);
});

// Accept upload link [public]
Route::get('/upload-links/accept', [UploadLinksController::class, 'accept']);
```

### 5. UploadsController

**Bestand**: `app/Http/Controllers/UploadsController.php` (GEWIJZIGD)

In `createShareFromUploads()` is de guest user flow uitgebreid. Wanneer een gastgebruiker een upload voltooit, wordt eerst gecontroleerd of die gast bij een upload link hoort (`UploadLink::where('guest_user_id', $user->id)`):

- **Upload link gevonden**: Share wordt gekoppeld aan de link-maker (`$uploadLink->user_id`), `use_count` wordt verhoogd, gebruiker wordt uitgelogd. Gastgebruiker wordt alleen verwijderd als `hasReachedUseLimit()` true retourneert.
- **Geen upload link**: Valt door naar de bestaande reverse share invite flow (ongewijzigd).

---

## Bestanden overzicht

| Bestand | Actie |
|---------|-------|
| `database/migrations/2026_02_16_120000_create_upload_links_table.php` | **Nieuw** |
| `app/Models/UploadLink.php` | **Nieuw** |
| `app/Http/Controllers/UploadLinksController.php` | **Nieuw** |
| `routes/api.php` | Gewijzigd (routes + import) |
| `app/Http/Controllers/UploadsController.php` | Gewijzigd (upload link guest flow) |

---

## Opmerking over de frontend

De frontend moet nog worden aangepast om de `upload_token` URL-parameter te herkennen en automatisch `GET /api/upload-links/accept?token=xxx` aan te roepen. Dit is vergelijkbaar met hoe de bestaande `invite_token` parameter werkt voor reverse shares.
