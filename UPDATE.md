# Upload Links Feature — Alle wijzigingen t.o.v. native Erugo

> **Datum:** februari 2026
> **Doel:** Een volledig nieuw API-endpoint waarmee je herbruikbare upload-links kunt aanmaken, delen en beheren — onafhankelijk van de bestaande reverse share functionaliteit.

---

## Inhoudsopgave

1. [Samenvatting](#samenvatting)
2. [Waarom een nieuw endpoint?](#waarom-een-nieuw-endpoint)
3. [Architectuuroverzicht](#architectuuroverzicht)
4. [Gewijzigde en nieuwe bestanden](#gewijzigde-en-nieuwe-bestanden)
5. [Database: nieuwe tabel `upload_links`](#database-nieuwe-tabel-upload_links)
6. [Backend: nieuwe controller en model](#backend-nieuwe-controller-en-model)
7. [Backend: wijzigingen in bestaande code](#backend-wijzigingen-in-bestaande-code)
8. [Frontend: wijzigingen](#frontend-wijzigingen)
9. [Environment / configuratie](#environment--configuratie)
10. [API Reference — stap voor stap](#api-reference--stap-voor-stap)
11. [Upload flow van A tot Z](#upload-flow-van-a-tot-z)
12. [Verschil met Reverse Shares](#verschil-met-reverse-shares)
13. [Lokale development setup](#lokale-development-setup)

---

## Samenvatting

Native Erugo heeft een "Reverse Share" systeem: je stuurt iemand een email-invite en diegene kan eenmalig bestanden uploaden. De link is gekoppeld aan een JWT-token dat na 60 minuten verloopt, en de invite is single-use.

**Onze Upload Links feature lost dit op:**
- Upload links zijn **herbruikbaar** (configureerbaar: onbeperkt of max N keer)
- Verloop is **instelbaar** (1-365 dagen, standaard 30 dagen)
- De link wordt **in de API response** teruggegeven (geen email nodig)
- Gebruikt een **opaque token** (64 tekens) in plaats van een JWT — verloopt dus niet na 60 minuten
- Na een upload blijft de gebruiker ingelogd en kan direct opnieuw uploaden
- Volledig nieuw endpoint: **bestaande reverse share functionaliteit is ongewijzigd**

---

## Waarom een nieuw endpoint?

De bestaande reverse shares hadden drie beperkingen:

| Probleem | Reverse Shares | Upload Links |
|----------|---------------|-------------|
| Link verloopt na 60 min (JWT) | Ja | Nee — opaque token, geldig tot `expires_at` |
| Slechts 1x te gebruiken | Ja | Nee — `max_uses: 0` = onbeperkt |
| Link alleen via email | Ja | Nee — URL in API response |
| Na upload: uitloggen + redirect | Ja | Nee — sessie blijft actief |

We hebben ervoor gekozen de reverse share code **niet** aan te passen maar een **volledig nieuw, parallel systeem** te bouwen. Zo blijft de bestaande functionaliteit 100% intact.

---

## Architectuuroverzicht

```
┌─────────────┐     POST /api/upload-links      ┌──────────────────┐
│  Admin/User  │ ─────────────────────────────── │  UploadLinks-    │
│  (Postman/   │     ← upload_url in response    │  Controller      │
│   curl/app)  │                                 │  ::create()      │
└─────────────┘                                  └──────────────────┘
                                                        │
                                                        │ maakt aan:
                                                        ▼
                                                 ┌──────────────────┐
                                                 │  upload_links    │
                                                 │  tabel (SQLite)  │
                                                 │  + guest User    │
                                                 └──────────────────┘

┌─────────────┐   GET /?upload_token=xxx         ┌──────────────────┐
│  Klant       │ ──────────────────────────────  │  Frontend        │
│  (browser)   │                                 │  auth.vue        │
└─────────────┘                                  └──────────────────┘
       │                                                │
       │                                                │ roept aan:
       │                                                ▼
       │         GET /api/upload-links/accept     ┌──────────────────┐
       │         ?token=xxx                       │  UploadLinks-    │
       │         ← JWT + guest session            │  Controller      │
       │                                          │  ::accept()      │
       │                                          └──────────────────┘
       │
       │  Upload bestanden via tus protocol
       │  POST http://tusd-server/files/
       │         ...chunks...
       │
       │  POST /api/uploads/create-share-from-uploads
       │         ← share aangemaakt
       │                                          ┌──────────────────┐
       │                                          │  UploadsController│
       │                                          │  upload link flow │
       │                                          │  (use_count++)   │
       │                                          └──────────────────┘
       │
       ▼
  Upload succesvol! Gebruiker blijft ingelogd,
  kan direct meer bestanden uploaden.
```

---

## Gewijzigde en nieuwe bestanden

### Nieuwe bestanden (3)

| Bestand | Beschrijving |
|---------|-------------|
| `database/migrations/2026_02_16_120000_create_upload_links_table.php` | Database migration voor de `upload_links` tabel |
| `app/Models/UploadLink.php` | Eloquent model met business logic |
| `app/Http/Controllers/UploadLinksController.php` | API controller met 4 endpoints |

### Gewijzigde bestanden (6)

| Bestand | Wat is gewijzigd |
|---------|-----------------|
| `routes/api.php` | Import + 4 nieuwe routes toegevoegd |
| `app/Http/Controllers/UploadsController.php` | Upload link guest flow in `createShareFromUploads()` |
| `resources/js/api.js` | Nieuwe `acceptUploadLink()` functie |
| `resources/js/components/auth.vue` | Handling van `upload_token` URL parameter |
| `resources/js/components/uploader.vue` | Upload link guest: toast i.p.v. redirect na upload |
| `resources/js/store.js` | Nieuw veld `uploadLinkGuest` |
| `resources/js/utils.js` | `getTusdUrl()` configureerbaar via `VITE_TUSD_URL` env var |
| `.env` | `VITE_TUSD_URL` toegevoegd (alleen voor lokale dev) |

---

## Database: nieuwe tabel `upload_links`

**Migration:** `database/migrations/2026_02_16_120000_create_upload_links_table.php`

```sql
CREATE TABLE upload_links (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,     -- wie heeft de link gemaakt (FK → users)
    guest_user_id   INTEGER NULL,         -- tijdelijke guest user (FK → users)
    name            VARCHAR(255) NOT NULL, -- beschrijvende naam, bijv. "Klant X projectbestanden"
    token           VARCHAR(64) UNIQUE,    -- opaque random token (geen JWT!)
    max_uses        INTEGER DEFAULT 0,     -- 0 = onbeperkt, 1 = single-use, N = max N keer
    use_count       INTEGER DEFAULT 0,     -- teller: hoeveel uploads zijn er gedaan
    expires_at      TIMESTAMP NOT NULL,    -- wanneer verloopt de link
    active          BOOLEAN DEFAULT TRUE,  -- kan handmatig gedeactiveerd worden
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP
);
```

### Belangrijke velden:

- **`token`** (64 tekens, uniek): Opaque random string gegenereerd met `Str::random(64)`. Dit is GEEN JWT-token — het verloopt niet onafhankelijk. De link werkt zolang `expires_at` niet verstreken is en `active = true`.
- **`max_uses`**: `0` = onbeperkt uploaden, `1` = eenmalig (daarna wordt de link gedeactiveerd), `N` = maximaal N keer uploaden.
- **`use_count`**: Wordt bij elke succesvolle upload opgehoogd met 1.
- **`guest_user_id`**: Verwijst naar een tijdelijke `User` met `is_guest = true`. Deze user wordt aangemaakt bij het creeren van de link en gebruikt als authenticatie-identiteit wanneer iemand de link opent.

---

## Backend: nieuwe controller en model

### Model: `app/Models/UploadLink.php`

```php
class UploadLink extends Model
{
    protected $fillable = [
        'user_id', 'guest_user_id', 'name', 'token',
        'max_uses', 'use_count', 'expires_at', 'active',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'max_uses'   => 'integer',
        'use_count'  => 'integer',
        'active'     => 'boolean',
    ];

    // token en guest_user_id worden NIET in JSON responses opgenomen
    protected $hidden = ['token', 'guest_user_id'];
}
```

**Business logic methodes:**

| Methode | Beschrijving |
|---------|-------------|
| `isExpired()` | `true` als `now() > expires_at` |
| `isActive()` | `true` als `active && !isExpired() && !hasReachedUseLimit()` |
| `hasReachedUseLimit()` | `true` als `max_uses > 0 && use_count >= max_uses` (bij `max_uses = 0` altijd `false`) |
| `incrementUseCount()` | Verhoogt `use_count` met 1 en slaat op |
| `creator()` | Relatie naar de `User` die de link heeft aangemaakt |
| `guestUser()` | Relatie naar de tijdelijke guest `User` |

### Controller: `app/Http/Controllers/UploadLinksController.php`

4 methodes, 4 endpoints:

#### 1. `create()` — POST /api/upload-links (auth vereist)

Maakt een nieuwe upload link aan.

**Request body:**
```json
{
    "name": "Klant X projectbestanden",
    "expires_days": 30,
    "max_uses": 0
}
```

| Veld | Type | Verplicht | Default | Beschrijving |
|------|------|-----------|---------|-------------|
| `name` | string | Ja | — | Beschrijvende naam voor de link |
| `expires_days` | integer | Nee | 30 | Aantal dagen geldig (1-365) |
| `max_uses` | integer | Nee | 0 | Max uploads (0 = onbeperkt) |

**Wat er intern gebeurt:**
1. Validatie van input
2. Aanmaken van een tijdelijke guest user (`is_guest: true`, random email/wachtwoord)
3. Genereren van een 64-teken random token (`Str::random(64)`)
4. Aanmaken van het `UploadLink` record in de database
5. Samenstellen van de `upload_url` (de link die je deelt)

**Response:**
```json
{
    "status": "success",
    "data": {
        "upload_link": {
            "id": 1,
            "user_id": 1,
            "name": "Klant X projectbestanden",
            "max_uses": 0,
            "use_count": 0,
            "expires_at": "2026-03-22T12:00:00.000000Z",
            "active": true,
            "created_at": "2026-02-20T12:00:00.000000Z",
            "updated_at": "2026-02-20T12:00:00.000000Z"
        },
        "upload_url": "http://localhost:8787/?upload_token=aB3d...64tekens",
        "token": "aB3d...64tekens"
    }
}
```

> **Let op:** `upload_url` is de link die je naar de klant stuurt. `token` is de ruwe token (voor als je de URL zelf wilt samenstellen).

#### 2. `index()` — GET /api/upload-links (auth vereist)

Geeft alle upload links van de ingelogde gebruiker terug.

**Response:**
```json
{
    "status": "success",
    "data": {
        "upload_links": [
            {
                "id": 1,
                "user_id": 1,
                "name": "Klant X projectbestanden",
                "max_uses": 0,
                "use_count": 3,
                "expires_at": "2026-03-22T12:00:00.000000Z",
                "active": true,
                "is_active": true,
                "created_at": "2026-02-20T12:00:00.000000Z",
                "updated_at": "2026-02-20T12:00:00.000000Z"
            }
        ]
    }
}
```

> **`is_active`** is een computed veld dat rekening houdt met expiry, use limit en active-status.

#### 3. `delete()` — DELETE /api/upload-links/{id} (auth vereist)

Verwijdert een upload link. De bijbehorende guest user wordt ook verwijderd.

**Response:**
```json
{
    "status": "success",
    "message": "Upload link deleted"
}
```

#### 4. `accept()` — GET /api/upload-links/accept?token=xxx (publiek, geen auth)

Dit endpoint wordt aangeroepen wanneer iemand de upload link opent. Het valideert de token en geeft een JWT-sessie terug voor de guest user.

**Query parameter:**
| Veld | Type | Beschrijving |
|------|------|-------------|
| `token` | string (64 tekens) | De opaque token uit de upload_url |

**Validatie checks (in volgorde):**
1. Token is exact 64 tekens
2. Token bestaat in de database
3. Link is actief (`active = true`)
4. Link is niet verlopen (`expires_at > now()`)
5. Link heeft de gebruikslimiet niet bereikt (`use_count < max_uses` of `max_uses = 0`)
6. Guest user bestaat

**Succesvolle response:**
```json
{
    "status": "success",
    "message": "Upload link accepted",
    "data": {
        "access_token": "eyJ0eXAi...",
        "token_type": "Bearer",
        "expires_in": 3600,
        "guest": true,
        "upload_link_name": "Klant X projectbestanden"
    }
}
```

**Foutresponses:**
| HTTP Code | Wanneer |
|-----------|---------|
| 422 | Token ontbreekt of is niet exact 64 tekens |
| 404 | Token niet gevonden in database |
| 410 (Gone) | Link verlopen, use limit bereikt, of gedeactiveerd |
| 500 | Guest user niet gevonden (link is kapot) |

---

## Backend: wijzigingen in bestaande code

### `routes/api.php`

**Toegevoegd (regel 22):**
```php
use App\Http\Controllers\UploadLinksController;
```

**Toegevoegd (regels 220-228):**
```php
// Upload links [auth]
Route::group(['prefix' => 'upload-links', 'middleware' => ['auth']], function ($router) {
    Route::post('/', [UploadLinksController::class, 'create'])->name('upload-links.create');
    Route::get('/', [UploadLinksController::class, 'index'])->name('upload-links.index');
    Route::delete('/{id}', [UploadLinksController::class, 'delete'])->name('upload-links.delete');
});

// Accept upload link [public]
Route::get('/upload-links/accept', [UploadLinksController::class, 'accept'])->name('upload-links.accept');
```

### `app/Http/Controllers/UploadsController.php`

**Import toegevoegd:**
```php
use App\Models\UploadLink;
```

**Wijziging in `createShareFromUploads()` (na regel 355):**

Vóór de bestaande reverse share guest flow is een nieuw blok toegevoegd voor upload link guests:

```php
if ($user->is_guest) {
    // NIEUW: Check of deze guest user bij een upload link hoort
    $uploadLink = UploadLink::where('guest_user_id', $user->id)->first();

    if ($uploadLink) {
        $share->public = false;
        $share->user_id = $uploadLink->user_id; // Koppel share aan de link-eigenaar
        $share->save();

        $uploadLink->incrementUseCount(); // use_count++

        if ($uploadLink->hasReachedUseLimit()) {
            // Limiet bereikt: deactiveer link, logout, verwijder guest user
            $uploadLink->active = false;
            $uploadLink->save();
            Auth::logout();
            $user->delete();
            // Wis refresh_token cookie
            $cookie = cookie('refresh_token', '', 0, null, null, false, true);
            return response()->json([
                'status' => 'success',
                'message' => 'Share created',
            ])->withCookie($cookie);
        }

        // Multi-use: sessie NIET beëindigen, user kan direct opnieuw uploaden
        return response()->json([
            'status' => 'success',
            'message' => 'Share created',
            'data' => ['share' => $share]
        ]);
    }

    // Bestaande reverse share guest flow (ongewijzigd)...
}
```

**Cruciaal verschil met reverse shares:** Bij multi-use upload links wordt de guest user NIET verwijderd en wordt er NIET uitgelogd. De sessie blijft actief zodat de klant direct meer bestanden kan uploaden.

---

## Frontend: wijzigingen

### `resources/js/store.js`

**Nieuw veld toegevoegd:**
```javascript
uploadLinkGuest: false,  // true als de huidige sessie via een upload link is gestart
```

Dit veld onderscheidt upload-link-guests van reverse-share-guests. Belangrijk omdat ze na een upload anders behandeld worden.

### `resources/js/api.js`

**Nieuwe functie (na `acceptReverseShareInvite`):**
```javascript
export const acceptUploadLink = async (token) => {
    const response = await fetch(`${apiUrl}/api/upload-links/accept?token=${token}`, {
        method: 'GET',
        credentials: 'include'
    })
    const data = await response.json()
    if (!response.ok) {
        throw new Error(data.message)
    }
    return buildAuthSuccessData(data)
}
```

### `resources/js/components/auth.vue`

**Import toegevoegd:**
```javascript
import { acceptUploadLink } from '../api'
```

**Nieuw blok in `onMounted()` (na de `invite_token` handling):**
```javascript
// Grab upload link token from url (for upload link guest users)
const uploadToken = urlParams.get('upload_token')
if (uploadToken) {
    try {
        const data = await acceptUploadLink(uploadToken)
        store.authSuccess(data)
        store.uploadLinkGuest = true
        toast.success('Upload link accepted')
        window.history.replaceState({}, document.title, window.location.pathname)
    } catch (error) {
        window.history.replaceState({}, document.title, window.location.pathname)
        toast.error(error.message || 'Upload link is invalid or expired')
    }
}
```

**Wat dit doet:**
1. Bij het laden van de pagina wordt de URL gecontroleerd op een `upload_token` parameter
2. Als die er is, wordt `acceptUploadLink()` aangeroepen (→ `GET /api/upload-links/accept?token=xxx`)
3. Bij succes: gebruiker wordt als guest ingelogd, `uploadLinkGuest = true` gezet, upload-interface verschijnt
4. De `upload_token` wordt uit de URL verwijderd (history.replaceState) voor een schonere URL

### `resources/js/components/uploader.vue`

**Gewijzigde upload-complete flow:**

```javascript
// Was:
if (store.isGuest()) {
    thankGuestForUpload()  // → logout + redirect naar bedank-pagina
}

// Is nu:
if (store.uploadLinkGuest) {
    // Upload link guest: blijf ingelogd, toon toast, sta meer uploads toe
    toast.success('Upload successful!')
} else if (store.isGuest()) {
    thankGuestForUpload()  // Ongewijzigd voor reverse share guests
} else {
    showSharePanel(createShareURL(result.data.share.long_id))
}
```

**Resultaat:** Na een succesvolle upload via een upload link:
- Er verschijnt een "Upload successful!" toast melding
- De gebruiker blijft ingelogd
- De upload-interface blijft beschikbaar
- De gebruiker kan direct meer bestanden uploaden
- De upload basket wordt geleegd (klaar voor nieuwe bestanden)

### `resources/js/utils.js`

**Wijziging in `getTusdUrl()`:**
```javascript
const getTusdUrl = () => {
    // NIEUW: Allow overriding via env variable for local development
    if (import.meta.env.VITE_TUSD_URL) {
        return import.meta.env.VITE_TUSD_URL
    }
    // Bestaand: tusd proxied through Caddy
    const protocol = window.location.protocol
    const host = window.location.host
    return `${protocol}//${host}/files/`
}
```

> **Noot:** Dit is alleen nodig voor lokale development waar er geen Caddy reverse proxy is. In productie met Caddy wordt `/files/` automatisch naar tusd geproxied.

---

## Environment / configuratie

### `.env` wijziging (alleen voor lokale development)

```env
VITE_TUSD_URL=http://localhost:8080/files/
```

> **In productie is deze variabele NIET nodig.** Caddy proxied `/files/` automatisch naar tusd.

---

## API Reference — stap voor stap

### Volledige workflow in Postman of curl

#### Stap 1: Inloggen (JWT token verkrijgen)

```bash
curl -X POST http://localhost:8787/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "dennis@gourami.eu",
    "password": "test1234"
  }'
```

**Response:**
```json
{
    "status": "success",
    "data": {
        "access_token": "eyJ0eXAiOiJKV1Qi...",
        "token_type": "Bearer",
        "expires_in": 3600
    }
}
```

> **Belangrijk:** Kopieer het `access_token`. Dit token is 60 minuten geldig. Je hebt het nodig voor alle volgende stappen.

#### Stap 2: Upload link aanmaken

```bash
curl -X POST http://localhost:8787/api/upload-links \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1Qi..." \
  -d '{
    "name": "Klant X projectbestanden",
    "expires_days": 30,
    "max_uses": 0
  }'
```

**Response bevat `upload_url`** — dit is de link die je naar de klant stuurt.

#### Stap 3: Upload link delen

Stuur de `upload_url` naar de klant. Bijvoorbeeld:
```
http://localhost:8787/?upload_token=aB3dEf...64tekens
```

De klant opent deze URL in de browser → de frontend herkent de `upload_token` parameter → roept automatisch de accept-endpoint aan → de klant is als guest ingelogd en ziet de upload-interface.

#### Stap 4: (Optioneel) Alle links bekijken

```bash
curl -X GET http://localhost:8787/api/upload-links \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1Qi..."
```

#### Stap 5: (Optioneel) Link verwijderen

```bash
curl -X DELETE http://localhost:8787/api/upload-links/1 \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1Qi..."
```

---

## Upload flow van A tot Z

Dit is wat er achter de schermen gebeurt wanneer een klant de upload link opent:

### 1. Klant opent URL in browser
```
http://jouw-erugo.nl/?upload_token=aB3dEf...
```

### 2. Frontend detecteert `upload_token` (auth.vue)
- Leest de `upload_token` uit de URL parameters
- Verwijdert de token uit de URL (voor security)

### 3. Frontend roept accept endpoint aan (api.js)
```
GET /api/upload-links/accept?token=aB3dEf...
```

### 4. Backend valideert en authenticeert (UploadLinksController::accept)
- Zoekt de upload link op basis van het token
- Checkt: actief? niet verlopen? limiet niet bereikt?
- Geeft een JWT-sessie uit voor de guest user
- Stuurt `access_token` terug

### 5. Frontend logt guest in (auth.vue + store.js)
- Slaat JWT op in de store
- Zet `store.uploadLinkGuest = true`
- Toont de upload-interface

### 6. Klant selecteert bestanden en klikt "Upload"

### 7. Bestanden worden geupload via tus protocol (uploader.vue + api.js)
- Bestanden gaan direct naar de tusd server (port 8080 lokaal, `/files/` via Caddy in productie)
- Tus protocol ondersteunt pauze/hervatten en chunked uploads

### 8. Na laatste bestand: share aanmaken
```
POST /api/uploads/create-share-from-uploads
```

### 9. Backend verwerkt de upload (UploadsController::createShareFromUploads)
- Herkent de guest user als upload-link-guest
- Koppelt de share aan de link-eigenaar (`$uploadLink->user_id`)
- Verhoogt `use_count` met 1
- **Multi-use:** sessie blijft actief, response bevat share data
- **Limiet bereikt:** deactiveert link, logout, verwijdert guest user

### 10. Frontend toont resultaat (uploader.vue)
- Toast melding: "Upload successful!"
- Upload basket wordt geleegd
- Klant kan direct meer bestanden uploaden (bij multi-use link)

---

## Verschil met Reverse Shares

| Eigenschap | Reverse Shares | Upload Links |
|-----------|---------------|-------------|
| Aanmaken via | API + email | API (link in response) |
| Token type | JWT (verloopt na 60 min) | Opaque token (64 tekens, verloopt niet los) |
| Herbruikbaar | Nee, single-use | Ja, configureerbaar (`max_uses`) |
| Na upload | Logout + redirect + guest verwijderd | Sessie blijft actief (multi-use) |
| Email vereist | Ja, recipient email | Nee |
| Accept endpoint | `GET /api/reverse-shares/accept?token=xxx` | `GET /api/upload-links/accept?token=xxx` |
| URL parameter | `?invite_token=xxx` | `?upload_token=xxx` |
| Frontend flag | `store.guest = true` | `store.uploadLinkGuest = true` |
| Share eigenaar | `invite->user` via `share->invite_id` | `uploadLink->user_id` via `share->user_id` |
| Bestaande code gewijzigd | Nee | Nee (alleen `UploadsController` uitgebreid) |

---

## Lokale development setup

### Vereisten
- PHP 8.x met Laravel 11
- Node.js (voor Vite)
- tusd binary (in `bin/tusd`)

### Servers starten (3 terminals)

**Terminal 1 — Laravel:**
```bash
cd /Users/account/Local\ Sites/erugo
php artisan serve --port=8787
```

**Terminal 2 — Vite (frontend hot reload):**
```bash
cd /Users/account/Local\ Sites/erugo
npx vite --port 5174
```

**Terminal 3 — tusd (upload server):**
```bash
cd /Users/account/Local\ Sites/erugo
./bin/tusd -port 8080 \
  -upload-dir storage/app/uploads \
  -hooks-http http://127.0.0.1:8787/api/tusd-hooks \
  -hooks-enabled-events pre-create,post-finish \
  -cors-allow-origin "http://localhost:8787" \
  -cors-allow-headers "Authorization,Content-Type,Upload-Offset,Upload-Length,Tus-Resumable,Upload-Metadata,Upload-Concat" \
  -cors-allow-methods "POST,HEAD,PATCH,OPTIONS,GET,DELETE"
```

### .env configuratie (lokaal)

Zorg dat deze variabele in `.env` staat:
```env
VITE_TUSD_URL=http://localhost:8080/files/
```

### Database migration

```bash
php artisan migrate
```

Dit maakt de `upload_links` tabel aan.

### Testen

1. Open `http://localhost:8787` in de browser
2. Log in met je admin account
3. Maak een upload link aan via Postman (zie Stap 1 & 2 hierboven)
4. Open de `upload_url` uit de response in een ander browser-venster (of incognito)
5. Upload een bestand
6. Controleer dat de share verschijnt in je admin account
7. Open dezelfde `upload_url` opnieuw — moet weer werken (multi-use)

---

## Routeoverzicht

| Method | URL | Auth | Beschrijving |
|--------|-----|------|-------------|
| `POST` | `/api/auth/login` | Nee | Inloggen, JWT verkrijgen |
| `POST` | `/api/upload-links` | Ja (Bearer) | Upload link aanmaken |
| `GET` | `/api/upload-links` | Ja (Bearer) | Alle upload links ophalen |
| `DELETE` | `/api/upload-links/{id}` | Ja (Bearer) | Upload link verwijderen |
| `GET` | `/api/upload-links/accept?token=xxx` | Nee | Upload link accepteren (guest login) |
| `GET` | `/api/uploads/verify/{uploadId}` | Ja (Bearer/Guest) | Upload sessie verifiëren |
| `POST` | `/api/uploads/create-share-from-uploads` | Ja (Bearer/Guest) | Share aanmaken na upload |
