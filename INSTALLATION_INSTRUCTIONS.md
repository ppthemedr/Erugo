# Fotoarchief — Installatie Handleiding

> **Versie:** november 2025 — D. Smith
> **Laatste update:** februari 2026

---

## Inhoudsopgave

1. [Architectuuroverzicht](#architectuuroverzicht)
2. [DNS configuratie](#dns-configuratie)
3. [Port forwarding](#port-forwarding)
4. [Linux installation](#linux-installation)
   - [Virtual machine aanmaken](#virtual-machine-aanmaken)
   - [Ubuntu Server installeren](#ubuntu-server-installeren)
   - [Vast IP-adres instellen](#vast-ip-adres-instellen)
   - [Timezone instellen](#timezone-instellen)
   - [Opslag toevoegen](#opslag-toevoegen)
   - [Samba installeren](#samba-installeren)
5. [Erugo installatie](#erugo-installatie)
6. [n8n installatie](#n8n-installatie)
7. [Docker Compose configuratie](#docker-compose-configuratie)
8. [Nginx reverse proxy](#nginx-reverse-proxy)
9. [Cloudflare Origin Certificate](#cloudflare-origin-certificate)
10. [SMTP configuratie](#smtp-configuratie)
11. [Updates uitvoeren](#updates-uitvoeren)
12. [WooCommerce (demo)](#woocommerce-demo)
13. [Beheer / toegang](#beheer--toegang)

---

## Architectuuroverzicht

```
Internet
  ↓ HTTPS
Cloudflare (proxy + DNS)
  ↓ HTTPS (Origin Certificate)
Nginx (poort 443)
  ├─ files.fotoarchief.com → Erugo (localhost:9998)
  └─ nn.fotoarchief.com    → n8n   (localhost:5678)
```

- **Erugo** draait intern op poort `9998`
- **n8n** draait intern op poort `5678`
- Alleen poort `443` staat open naar buiten
- SSL/TLS staat op **Full (strict)** in Cloudflare

---

## DNS configuratie

Stel de volgende DNS records in bij Cloudflare:

| Type | Naam | Doel | Proxy |
|------|------|------|-------|
| A | `files.fotoarchief.com` | `<SERVER_IP>` | Proxied (oranje wolk) |
| A | `nn.fotoarchief.com` | `<SERVER_IP>` | Proxied (oranje wolk) |

E-mail (SMTP2GO):

| Type | Naam | Waarde |
|------|------|--------|
| Volgens SMTP2GO configuratie — zie hun DNS setup wizard |

> **SMTP provider:** SMTP2GO — https://www.smtp2go.com/

---

## Port forwarding

Stel de volgende port forwards in op de router:

| Extern | Intern | Protocol | Doel |
|--------|--------|----------|------|
| 443 | 443 | TCP | HTTPS (Nginx) |
| `<SSH_POORT>` | 22 | TCP | SSH toegang |

> **Tip:** Gebruik een niet-standaard SSH-poort voor extra beveiliging.

---

## Linux installation

### Virtual machine aanmaken

Maak een nieuwe VM aan in Hyper-V (of VMware/Proxmox) met voldoende resources.

### Ubuntu Server installeren

1. Download de Ubuntu Server ISO
2. Mount de ISO in de VM
3. Doorloop de installatie

### Vast IP-adres instellen

Stel een vast IP-adres in via DHCP-reservering op de router:

| Instelling | Waarde |
|-----------|--------|
| IP | `<VAST_IP_ADRES>` (bijv. 192.168.x.x) |
| Methode | DHCP reservering (lokaal) |

### Timezone instellen

```bash
sudo timedatectl set-timezone Europe/Amsterdam
```

### Opslag toevoegen

Extra disk/storage koppelen aan `/mnt/erugo`:

**1. Maak disk aan in Hyper-V**

Voeg een nieuwe VHDX disk toe aan de VM via Hyper-V Manager.

**2. Maak partitie aan**

```bash
sudo fdisk /dev/sdX    # vervang sdX met de juiste disk
```

Maak een ext4 partitie aan.

**3. Maak mount directory**

```bash
sudo mkdir -p /mnt/erugo
```

**4. Voeg toe aan fstab**

Zoek eerst de UUID:
```bash
sudo blkid
```

Voeg toe aan `/etc/fstab`:
```
/dev/disk/by-uuid/<DISK_UUID>  /mnt/erugo  ext4  defaults  0  2
```

**5. Test**

```bash
sudo mount -a
df -h /mnt/erugo
```

Reboot en controleer of de storage automatisch gemount wordt.

### Samba installeren

Samba zorgt ervoor dat je vanaf Windows clients bij de geuploadde bestanden kunt:

```bash
sudo apt install samba
```

Zet de juiste eigenaar:
```bash
sudo chown -R <GEBRUIKERSNAAM>:<GEBRUIKERSNAAM> /mnt/erugo
```

Stel een Samba wachtwoord in:
```bash
sudo smbpasswd -a <GEBRUIKERSNAAM>
```

Bewerk de Samba configuratie:
```bash
sudo nano /etc/samba/smb.conf
```

Voeg onderaan toe:
```ini
[erugo]
   path = /mnt/erugo
   browseable = yes
   read only = no
   writable = yes
   valid users = <GEBRUIKERSNAAM>
   force user = <GEBRUIKERSNAAM>
   force group = <GEBRUIKERSNAAM>
```

Herstart Samba:
```bash
sudo systemctl restart smbd
```

> **Toegang vanaf Windows:** Open Verkenner → `\\<SERVER_IP>\erugo`

---

## Erugo installatie

> **Officieel:** https://erugo.app/getting-started/
> **URL na installatie:** https://files.fotoarchief.com

Volg de officiële installatie-instructies, maar met de volgende aanpassingen:

- Storage volume gaat naar `/mnt/erugo` (externe disk)
- Caddy wordt vervangen door Nginx als reverse proxy

### Docker Compose (alleen Erugo + Caddy — originele setup)

> **Let op:** Dit was de initiële setup. Zie [Docker Compose configuratie](#docker-compose-configuratie) voor de huidige setup met n8n + Nginx.

```yaml
services:
  app:
    image: wardy784/erugo:latest
    container_name: erugo
    restart: unless-stopped
    volumes:
      - /mnt/erugo:/var/www/html/storage
    ports:
      - "9998:80"
    networks:
      - erugo

  caddy:
    image: caddy:latest
    container_name: erugo-caddy
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile
      - caddy-data:/data
      - caddy-config:/config
    networks:
      - erugo
    depends_on:
      - app

networks:
  erugo:
    driver: bridge

volumes:
  caddy-data:
  caddy-config:
```

---

## n8n installatie

> **URL:** https://nn.fotoarchief.com
> **Poort intern:** 5678

n8n draait samen met Erugo in één docker-compose stack. De configuratie staat in de volgende sectie.

> **Licentie:** Community edition met paid features. License key wordt beheerd via de n8n UI onder Settings → License.

---

## Docker Compose configuratie

> **Locatie:** `/opt/dockercompose/docker-compose.yml`

Dit is de huidige (januari 2026) configuratie met Erugo + n8n:

```yaml
services:
  erugo:
    image: wardy784/erugo:latest
    container_name: erugo
    restart: unless-stopped
    volumes:
      - /opt/dockercompose/erugo/app:/var/www/html
      - /mnt/erugo:/var/www/html/storage
    ports:
      - "9998:80"
    networks:
      - backend

  n8n:
    image: docker.n8n.io/n8nio/n8n:latest
    container_name: n8n
    restart: unless-stopped
    ports:
      - "5678:5678"
    volumes:
      - n8n-data:/home/node/.n8n
    environment:
      N8N_HOST: nn.fotoarchief.com
      N8N_PORT: 5678
      N8N_PROTOCOL: https
      N8N_TRUST_PROXY: "true"
      N8N_PROXY_HOPS: 2
      WEBHOOK_URL: https://nn.fotoarchief.com
    networks:
      - backend

networks:
  backend:
    driver: bridge

volumes:
  n8n-data:
```

### Bestandslocaties (belangrijk)

| Onderdeel | Pad |
|-----------|-----|
| docker-compose | `/opt/dockercompose/docker-compose.yml` |
| n8n volume | Docker volume `n8n-data` |
| Erugo storage | `/mnt/erugo` |
| Erugo app | `/opt/dockercompose/erugo/app` |
| Nginx vhost | `/etc/nginx/sites-available/fotoarchief.conf` |
| Cloudflare origin cert | `/etc/ssl/cloudflare/origin.pem` |
| Cloudflare private key | `/etc/ssl/cloudflare/origin.key` |

---

## Nginx reverse proxy

Externe toegang loopt via Nginx met een Cloudflare Origin Certificate (niet via Caddy).

### Nginx installeren

```bash
sudo apt install -y nginx
```

### Virtual host configuratie

Bestand: `/etc/nginx/sites-available/fotoarchief.conf`

```nginx
# Erugo — files.fotoarchief.com
server {
    listen 443 ssl http2;
    server_name files.fotoarchief.com;

    ssl_certificate     /etc/ssl/cloudflare/origin.pem;
    ssl_certificate_key /etc/ssl/cloudflare/origin.key;

    location / {
        proxy_pass http://127.0.0.1:9998;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }
}

# n8n — nn.fotoarchief.com
server {
    listen 443 ssl http2;
    server_name nn.fotoarchief.com;

    ssl_certificate     /etc/ssl/cloudflare/origin.pem;
    ssl_certificate_key /etc/ssl/cloudflare/origin.key;

    location / {
        proxy_pass http://127.0.0.1:5678;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 3600;
    }
}
```

### Activeren

```bash
sudo ln -s /etc/nginx/sites-available/fotoarchief.conf /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

---

## Cloudflare Origin Certificate

Het Origin Certificate zorgt voor versleutelde verbinding tussen Cloudflare en de server.

### Aanmaken in Cloudflare

1. Ga naar Cloudflare Dashboard → SSL/TLS → Origin Server
2. Klik "Create Certificate"
3. Hostnames: `*.fotoarchief.com`, `fotoarchief.com`
4. Geldigheid: 15 jaar (standaard)
5. Kopieer het **Origin Certificate** en de **Private Key**

### Opslaan op de server

```bash
sudo mkdir -p /etc/ssl/cloudflare

# Plak het Origin Certificate
sudo nano /etc/ssl/cloudflare/origin.pem

# Plak de Private Key
sudo nano /etc/ssl/cloudflare/origin.key
```

### Rechten zetten

```bash
sudo chmod 644 /etc/ssl/cloudflare/origin.pem
sudo chmod 600 /etc/ssl/cloudflare/origin.key
```

> **BELANGRIJK:** Sla het certificaat en de private key veilig op in een wachtwoordmanager (bijv. 1Password, Bitwarden). Ze staan NIET in dit document.

### Cloudflare SSL instelling

Zet in Cloudflare Dashboard → SSL/TLS:
- **Encryption mode:** Full (strict)

---

## SMTP configuratie

E-mail wordt verstuurd via **SMTP2GO** (https://www.smtp2go.com/).

Configureer SMTP in Erugo via de admin interface:

| Instelling | Waarde |
|-----------|--------|
| SMTP Host | `<SMTP2GO_HOST>` |
| SMTP Port | `<SMTP2GO_PORT>` (bijv. 587) |
| SMTP Username | `<SMTP2GO_USERNAME>` |
| SMTP Password | `<SMTP2GO_PASSWORD>` |
| From Address | `<AFZENDER_EMAIL>` |
| Encryption | TLS |

> **Credentials:** Staan in de wachtwoordmanager, niet in dit document.

---

## Updates uitvoeren

### Erugo en n8n tegelijk updaten

```bash
# Ga naar de docker-compose map
cd /opt/dockercompose

# (Optioneel) Maak back-ups
# Erugo: backup via admin panel of kopieer /mnt/erugo
# n8n: docker volume backup

# Pull nieuwste images
docker compose pull

# Herstart containers met nieuwe images
docker compose up -d
```

### Specifieke Erugo versie pinnen

Wijzig in `docker-compose.yml`:
```yaml
image: wardy784/erugo:<VERSIE_TAG>
```

Daarna:
```bash
docker compose up -d
```

### Controle na update

```bash
# Check of containers draaien
docker ps

# Bekijk logs op fouten
docker compose logs

# Of per service
docker compose logs erugo
docker compose logs n8n
```

### Browser check

- https://files.fotoarchief.com — Erugo
- https://nn.fotoarchief.com — n8n

---

## WooCommerce (demo)

> **URL:** https://demo.fotoarchief.com/wp-login.php

WooCommerce staat geinstalleerd op een aparte demo-omgeving met een test product.

> **Credentials:** Staan in de wachtwoordmanager, niet in dit document.

---

## Beheer / toegang

Alle credentials (SSH, Erugo admin, n8n, WooCommerce, SMTP, Samba, Cloudflare, n8n license key) worden beheerd via de wachtwoordmanager.

### SSH toegang

```bash
ssh -p <SSH_POORT> <GEBRUIKERSNAAM>@files.fotoarchief.com
```

### Overzicht services

| Service | URL | Poort intern |
|---------|-----|-------------|
| Erugo | https://files.fotoarchief.com | 9998 |
| n8n | https://nn.fotoarchief.com | 5678 |
| WooCommerce (demo) | https://demo.fotoarchief.com | — |
| Nginx | — | 443 |

### Containers beheren

```bash
cd /opt/dockercompose

# Status bekijken
docker ps

# Herstarten
docker compose restart

# Stoppen
docker compose down

# Starten
docker compose up -d

# Logs volgen (live)
docker compose logs -f

# Logs van specifieke service
docker compose logs -f erugo
docker compose logs -f n8n
```
