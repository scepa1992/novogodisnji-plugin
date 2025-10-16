# Velora Doček REST API Plugin

WordPress plugin za automatsko kreiranje dočeka Nove godine sa AI generisanim sadržajem, SEO optimizacijom i automatskim update-ovima.

## Funkcionalnosti

- 🤖 AI generisanje sadržaja (OpenAI GPT-4o-mini)
- 🔍 SEO optimizacija (Slim SEO integracija)
- 📊 JSON-LD schema (Event format)
- 🏷️ Automatska kategorizacija i tagovanje
- 🖼️ Upload featured slika
- 🔄 GitHub auto-update (privatni repo)
- 🛡️ Rate limiting i sigurnosne mere

## Instalacija

1. Upload plugin u `/wp-content/plugins/velora-rest-api/`
2. Aktiviraj plugin u WordPress adminu
3. Postavi environment varijable (opciono)

## Konfiguracija

### Environment varijable (preporučeno)

```bash
# GitHub token za privatni repo
export VELORA_GITHUB_TOKEN="ghp_your_token_here"

# API ključ za kreiranje dočeka
export VELORA_API_KEY="your_api_key_here"
```

### wp-config.php konstante (alternativa)

```php
define('VELORA_GITHUB_TOKEN', 'ghp_your_token_here');
define('VELORA_API_KEY', 'your_api_key_here');
```

## API korišćenje

### Endpoint
```
POST /wp-json/velora/v1/create-docek
```

### Parametri
- `key` - API ključ (obavezan)
- `title` - Naziv dočeka (obavezan)
- `muzika` - Muzika/izvođač
- `hrana` - Hrana
- `pice` - Piće
- `cena` - Cena
- `tip` - Tip dočeka (Hoteli, Restorani, Kafane, Klubovi, Splavovi, Ostalo)
- `preporuka` - Boolean za tag "Preporuka"
- `slika` - URL slike (http/https)
- `staticki` - HTML statični sadržaj

### Primer odgovora
```json
{
  "success": true,
  "post_id": 123,
  "url": "https://example.com/docek-nove-godine/docek-123/",
  "message": "✅ Doček uspešno kreiran!"
}
```

## Sigurnosne mere

- ✅ Rate limiting (10 zahteva/IP/5min)
- ✅ API ključ autentifikacija
- ✅ Idempotency zaštita
- ✅ Input sanitizacija
- ✅ Environment varijable za sensitive podatke
- ✅ Private GitHub repo

## Auto-update

Plugin automatski proverava GitHub releases i nudi update-e:
- Repo mora biti privatan
- Potreban GitHub Personal Access Token (scope: repo)
- Tag format: vX.Y.Z (npr. v6.0.1)

## Zahtevi

- WordPress 5.0+
- PHP 7.4+
- Slim SEO plugin (opciono)
- Custom Post Type UI (za CPT i taksonomije)

## Podrška

Za pitanja i probleme, kontaktirajte Velora tim.

## Licenca

Privatni plugin - sva prava zadržana.
