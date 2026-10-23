# Fiscal Hub

📄 **Fiscal documents, DANFE generation & business lookups — in the browser**

Fiscal Hub is a modern, progressive web application for generating and visualizing Brazilian electronic invoice documents (DANFE — Documento Auxiliar da Nota Fiscal Eletrônica). It also provides CNPJ lookups, offline XML-to-PDF generation, online API lookup by access key, camera barcode scanning, and a comprehensive municipal NF-e consultation directory — all packaged in a lightweight Docker container.

<!-- buttons -->
[![Stars](https://img.shields.io/github/stars/ivancarlosti/fiscalhub?label=⭐%20Stars&color=gold&style=flat)](https://github.com/ivancarlosti/fiscalhub/stargazers)
[![Watchers](https://img.shields.io/github/watchers/ivancarlosti/fiscalhub?label=Watchers&style=flat&color=red)](https://github.com/sponsors/ivancarlosti)
[![Forks](https://img.shields.io/github/forks/ivancarlosti/fiscalhub?label=Forks&style=flat&color=ff69b4)](https://github.com/sponsors/ivancarlosti)
[![Downloads](https://img.shields.io/github/downloads/ivancarlosti/fiscalhub/total?label=Downloads&color=success)](https://github.com/ivancarlosti/fiscalhub/releases)
[![GitHub commit activity](https://img.shields.io/github/commit-activity/m/ivancarlosti/fiscalhub?label=Activity)](https://github.com/ivancarlosti/fiscalhub/pulse)
[![GitHub Issues](https://img.shields.io/github/issues/ivancarlosti/fiscalhub?label=Issues&color=orange)](https://github.com/ivancarlosti/fiscalhub/issues)  
[![License](https://img.shields.io/github/license/ivancarlosti/fiscalhub?label=License)](LICENSE)
[![GitHub last commit](https://img.shields.io/github/last-commit/ivancarlosti/fiscalhub?label=Last%20Commit)](https://github.com/ivancarlosti/fiscalhub/commits)
[![Security](https://img.shields.io/badge/Security-View%20Here-purple)](https://github.com/ivancarlosti/fiscalhub/security)
[![Code of Conduct](https://img.shields.io/badge/Code%20of%20Conduct-2.1-4baaaa)](https://github.com/ivancarlosti/fiscalhub?tab=coc-ov-file)
<!-- endbuttons -->

---

## Screenshots

All images are real captures of the app; see [`docs/screenshots/README.md`](docs/screenshots/README.md) to regenerate them.

| Upload de XML (light) | Consulta por chave |
| --- | --- |
| ![Upload de XML tab in the light theme](docs/screenshots/01-upload-xml-light.png) | ![Access key lookup tab](docs/screenshots/02-consulta-chave.png) |

| Consulta NF-e | Consulta CNPJ |
| --- | --- |
| ![Municipal NF-e consultation directory](docs/screenshots/03-consulta-nfe.png) | ![CNPJ lookup tab](docs/screenshots/04-consulta-cnpj.png) |

| Upload de XML (dark) | Login (account) |
| --- | --- |
| ![Upload de XML tab in the dark theme](docs/screenshots/05-upload-xml-dark.png) | ![Account login form](docs/screenshots/06-login-account.png) |

| Login (Keycloak) | Mobile layout |
| --- | --- |
| ![Keycloak single sign-on gate](docs/screenshots/07-login-keycloak.png) | ![Mobile layout](docs/screenshots/08-mobile.png) |

---

## Features

### 📄 DANFE PDF Generation (Offline)
- **XML Upload** — Drag-and-drop or select an NFe XML file. Parses the official `http://www.portalfiscal.inf.br/nfe` namespace and extracts all fields: emitter, recipient, products, taxes (ICMS, IPI), transport, and totals.
- **Authentic Layout** — Generates a pixel-perfect DANFE PDF matching the official SEFAZ layout with all quadrants (A through H), product table, tax calculation, transport details, and access key barcode area.
- **100% Offline** — XML parsing and PDF generation happen entirely in the browser using `pdfmake`. No server-side processing, no API calls, no internet required.

### 🔑 Access Key Lookup (Online)
- **44-Digit Key Input** — Masked input field with real-time digit counter, paste support, and validation.
- **Meu Danfe API Integration** — Server-side PHP proxy forwards access keys to the Meu Danfe API v2, retrieves the official DANFE PDF, and returns it to the browser.
- **Smart Retry Logic** — If the NFe is not yet in the account, the proxy automatically adds it via the API and polls until the SEFAZ query completes (up to 30 seconds).

### 🏢 CNPJ Lookup (BrasilAPI)
- **Full CNPJ Lookup** — Enter a 14-position CNPJ (numeric or alphanumeric) to open the complete company record: legal name, trade name, status, founding date, main activity, legal nature, size, capital, and address.
- **Alphanumeric Support** — Accepts the new alphanumeric CNPJ format (`AA.AAA.AAA/AAAA-DV`) with uppercase normalization, live input masking, and full check-digit validation (módulo 11).
- **Partners (QSA)** — The company's partners and officers are displayed directly from the CNPJ response (`qsa`), including role and entry date.
- **Browser History** — Recent lookups are persisted in `localStorage` and can be re-run with one click — no server-side database required.

### 📸 Camera Barcode Scanner
- **Quagga2 Barcode Detection** — Scan the 44-digit access key directly from the DANFE barcode using your device's camera (Code 128 and Code 39).
- **Dual Context** — Use the scanner from the "Search by Key" tab (auto-fills the input) or from the "Upload XML" tab (copies the key for SEFAZ portal use).
- **Camera Controls** — Toggle front/rear camera, retry, and zoom slider for fine-tuning barcode detection.

### 🌐 NF-e Municipal Consultation Directory
- **National System Banner** — Quick link to the unified national NFS-e public query portal (`nfse.gov.br`).
- **45+ Municipalities** — Searchable, filterable table of city-level NF-e consultation portals across all Brazilian states, with system classification (GINFES, Nota Paulistana, Nota Carioca, and independent portals).
- **GINFES Tip** — Helpful note explaining how to use other GINFES-based city portals.

### 🎨 Modern UI/UX
- **Dark & Light Themes** — Full dark mode support with persistent preference stored in `localStorage`.
- **Internationalization** — Portuguese (pt), English (en), and Spanish (es) translations for all UI strings.
- **Toast Notifications** — Non-intrusive, auto-dismissing notifications for success, error, warning, and info messages.
- **Loading Overlay** — Context-aware loading messages for each processing stage.
- **Responsive Design** — Works on desktop, tablet, and mobile with adaptive layouts.

### 🔐 Flexible Authentication
Three auth modes configurable via environment variable:

| Mode | Description |
|------|-------------|
| `none` | No authentication (default, open access) |
`account` | Session-based login with a custom HTML login form (username/password) |
| `keycloak` | OpenID Connect SSO via Keycloak with optional email restriction |

### 🐳 Docker-Ready
- **Lightweight Image** — Based on `php:8.5-fpm-alpine` (slim Alpine base).
- **Multi-Arch** — Automatic builds for `linux/amd64` and `linux/arm64` via GitHub Actions.
- **Non-Root** — Container runs as unprivileged `phpuser` (UID 1000).
- **Reverse Proxy Ready** — Configurable `PORT` and `DOMAIN` environment variables for seamless integration behind Nginx, Traefik, Caddy, or any reverse proxy.

---

## Architecture

```
┌────────────────────────┐      ┌─────────────────────────┐
│    Docker Container    │      │   Meu Danfe API v2      │
│                        │      │   (external, SaaS)      │
│  ┌──────────────────┐  │      │                         │
│  │ fiscalhub_app   │  │      │  api.meudanfe.com.br    │
│  │                  │  │      │                         │
│  │  ┌────────────┐  │  │      │  GET  /fd/get/da/{key}  │
│  │  │ index.html │  │  │      │  PUT  /fd/add/{key}     │
│  │  │ app.js     │──┼──┼──┐   │                         │
│  │  │ styles.css │  │  │  │   └─────────────────────────┘
│  │  └────────────┘  │  │  │
│  │                  │  │  │   ┌─────────────────────────┐
│  │  ┌────────────┐  │  │  │   │   Keycloak (optional)   │
│  │  │ proxy.php  │──┼──┼──┤   │                         │
│  │  │ auth.php   │  │  │  │   │  OIDC /userinfo         │
│  │  └────────────┘  │  │  │   └─────────────────────────┘
│  │        │         │  │  │
│  │  Static files ───┼──┼──┼──► Port 8080
│  │  (no processing) │  │  │    (configurable)
│  └──────────────────┘  │  │
└────────────────────────┘  │
                            │
                     Browser (SPA)
```

### Data Flow

1. **XML Upload Path (Offline):** User drops an NFe XML → `app.js` parses with `DOMParser` → extracts NFe data → builds official DANFE layout → `pdfmake` generates PDF blob → download triggered. **No server involvement.**

2. **Access Key Path (Online):** User enters 44-digit key → `app.js` POSTs to `proxy.php` → `auth.php` validates credentials → `proxy.php` calls Meu Danfe API v2 → if NFe not found, adds it and polls → transforms response to `{pdf_base64}` format → `app.js` converts base64 to PDF blob → download triggered.

3. **Barcode Path:** Camera opens → Quagga2 scans Code 128/39 barcode → extracts 44 digits → auto-fills input or copies to clipboard → user proceeds with online lookup or manual SEFAZ consultation.

4. **CNPJ Lookup Path (Online):** User selects the "Consulta CNPJ" tab → enters a 14-position CNPJ (numeric or alphanumeric) → `app.js` validates the check digits and POSTs an action (`cnpj`) to `brasilapi-proxy.php` → `auth.php` validates credentials → `brasilapi-proxy.php` re-validates and calls the BrasilAPI (`GET /api/cnpj/v1/{cnpj}`) → returns the JSON response for rendering (including the `qsa` partners list). Successful lookups are stored in `localStorage` history.

### Project Structure

```
fiscalhub/
├── webapp/
│   ├── index.html                # Single-page application shell
│   ├── app.js                    # Core logic: tabs, PDF gen, API, i18n, camera
│   ├── styles.css                # Complete stylesheet (light + dark themes)
│   ├── router.php                # PHP router: enforces auth on every request
│   ├── auth.php                  # Authentication module (session/OIDC)
│   ├── proxy.php                 # Server-side CORS proxy for Meu Danfe API
│   ├── brasilapi-proxy.php       # Server-side CORS proxy for BrasilAPI
│   ├── login.html                # Login form page (account mode, reCAPTCHA)
│   ├── login.php                 # Login handler (validates credentials)
│   ├── auth-config.php           # Public login config (auth method + site key)
│   ├── logout.php                # Logout handler (destroys session)
│   ├── keycloak-login.php        # Keycloak Authorization Code + PKCE entry point
│   └── keycloak-callback.php     # Keycloak callback (code exchange, session)
├── docker/
│   ├── docker-compose.yml        # Single-service container stack
│   └── .env                      # Configuration template
├── docs/
│   └── screenshots/              # README captures, gallery and capture tooling
├── plans/                        # Dated implementation notes (YYYY-MM-DD-*.md)
├── .github/
│   └── workflows/
│       ├── build.yml             # Build, tag, release + multi-arch Docker push
│       ├── keepalive.yml         # Weekly keepalive commit
│       ├── semantic-release.yml  # Version, tag and GitHub Release
│       └── update-readme.yml     # Daily README badge/footer sync
├── Dockerfile                    # PHP 8.5 FPM + nginx image
├── .dockerignore                 # Exclude secrets and dev files from image
├── LICENSE                       # MIT License
└── README.md                     # This file
```

---

## Setup Instructions

### Prerequisites
- Docker & Docker Compose installed
- (Optional) A [Meu Danfe](https://meudanfe.com.br) API key for online access key lookups. The XML-to-PDF feature works without any API key.

### 1. Configure Environment

Navigate into the `docker` directory and edit the `.env` file with your settings:

```bash
cd docker
nano .env
```

**Minimum settings (offline-only, no API, no auth):**
```env
PORT=8080
AUTH_METHOD=none
```

**Full settings (online API + authentication):**
```env
PORT=8080
DOMAIN=fiscalhub.example.com

# Meu Danfe API v2 (required for access key lookups)
FISCALHUB_API_BASE=https://api.meudanfe.com.br/v2
FISCALHUB_API_KEY=your-api-key-here
FISCALHUB_API_TIMEOUT=60

# BrasilAPI (free CNPJ lookups, no API key required)
BRASILAPI_API_BASE=https://brasilapi.com.br/api/cnpj/v1
BRASILAPI_API_TIMEOUT=10

# Authentication
AUTH_METHOD=account
ACCOUNT_LOGIN=admin
ACCOUNT_PASSWORD=your_secure_password
```

### 2. Start the Application

```bash
docker compose up -d
```

### 3. Access the Application

Navigate to `http://localhost:8080` (or your configured `PORT`).

The default tab is "Upload XML" — drop an NFe XML file to instantly generate a DANFE PDF. Switch to "Search by Key" for online API lookups.

---

## Environment Variables Reference

### Web Server & Proxy

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `PORT` | No | `8080` | Host port mapping for the application |
| `DOMAIN` | No | — | Public domain for reverse proxy reference |

### Meu Danfe API

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `FISCALHUB_API_BASE` | No | `https://api.meudanfe.com.br/v2` | Meu Danfe API base URL |
| `FISCALHUB_API_KEY` | No* | — | Your Meu Danfe API key (*required for online lookups) |
| `FISCALHUB_API_TIMEOUT` | No | `60` | Seconds before timing out SEFAZ queries |

> The former `MEUDANFE_API_*` variables are still read as a fallback, so an existing `docker/.env` keeps working; `FISCALHUB_API_*` is the documented name.

### BrasilAPI

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `BRASILAPI_API_BASE` | No | `https://brasilapi.com.br/api/cnpj/v1` | BrasilAPI CNPJ endpoint base URL |
| `BRASILAPI_API_TIMEOUT` | No | `10` | Seconds before timing out BrasilAPI queries |

> BrasilAPI is free and requires no API key.

### Authentication

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `AUTH_METHOD` | No | `none` | `none`, `account`, or `keycloak` |
| `ACCOUNT_LOGIN` | No | — | Username for `account` auth |
| `ACCOUNT_PASSWORD` | No | — | Password for `account` auth |
| `RECAPTCHA_SITE_KEY` | No | — | Google reCAPTCHA v2 site key (enables CAPTCHA in `account` mode) |
| `RECAPTCHA_SECRET_KEY` | No | — | Google reCAPTCHA v2 secret key (server-side verification) |
| `RECAPTCHA_VERIFY_URL` | No | Google endpoint | Override the siteverify URL (testing only) |
| `KEYCLOAK_BASE_URL` | No | — | Keycloak server URL |
| `KEYCLOAK_REALM` | No | — | Keycloak realm name |
| `KEYCLOAK_CLIENT_ID` | No | — | Keycloak client ID |
| `KEYCLOAK_CLIENT_SECRET` | No | — | Keycloak client secret |
| `KEYCLOAK_REDIRECT_URI` | No | — | Where Keycloak sends the browser back **after a logout** (post logout redirect URI) |
| `KEYCLOAK_CALLBACK_URI` | No | request URL | Full URL of `/keycloak-callback.php` when it cannot be derived from the request; must be registered on the client |
| `KEYCLOAK_EMAIL_ACCOUNT` | No | — | Restrict access to this email (both flows) |

---

## Authentication Options

### None (Default)
```env
AUTH_METHOD=none
```
Application is publicly accessible. No login required.

### Account (Session-Based Login Form)
```env
AUTH_METHOD=account
ACCOUNT_LOGIN=admin
ACCOUNT_PASSWORD=your_secure_password
```
Visitors are redirected to a styled login page (`login.html`) where they enter their credentials. On success, a PHP session is created and they are redirected to the application. The session cookie (`PHPSESSID`) is `HttpOnly`, `SameSite=Strict`, and `Secure` (when behind HTTPS). A logout button in the header destroys the session and returns to the login page.

Session credentials are compared using `hash_equals()` for timing-attack resistance.

#### Optional: Google reCAPTCHA v2

```env
AUTH_METHOD=account
ACCOUNT_LOGIN=admin
ACCOUNT_PASSWORD=your_secure_password
RECAPTCHA_SITE_KEY=your_site_key
RECAPTCHA_SECRET_KEY=your_secret_key
```

When **both** reCAPTCHA keys are set, the login page renders a Google reCAPTCHA v2 checkbox. The response token is verified server-side against `https://www.google.com/recaptcha/api/siteverify` **before** the credentials are compared, so the form cannot be used as a credential oracle. Failed verifications redirect back with `?error=captcha` and the widget is reset (tokens are single-use). The secret key never leaves the server — `auth-config.php` exposes only the public site key.

Verification **fails closed** (blocks login) if Google's endpoint is unreachable or returns an error, and every rejection is written to the PHP error log together with Google's `error-codes`. Leaving the keys empty disables the feature and restores the previous behavior.

> reCAPTCHA applies to `account` mode only; `keycloak` mode relies on the identity provider's own protections.

### Keycloak (SSO)
```env
AUTH_METHOD=keycloak
KEYCLOAK_BASE_URL=https://sso.example.com
KEYCLOAK_REALM=YourRealm
KEYCLOAK_CLIENT_ID=fiscalhub
KEYCLOAK_CLIENT_SECRET=your_client_secret
KEYCLOAK_REDIRECT_URI=https://fiscalhub.example.com/
KEYCLOAK_CALLBACK_URI=
KEYCLOAK_EMAIL_ACCOUNT=you@example.com
```
Two ways to authenticate against Keycloak:

1. **Browser login (built in).** In `keycloak` mode the login page shows an
   *Entrar com Keycloak* button that starts the OpenID Connect **Authorization
   Code + PKCE** flow (`/keycloak-login.php` → provider →
   `/keycloak-callback.php`). The code is exchanged server side with the client
   secret, the access token is validated against the realm's `/userinfo`
   endpoint and only then is the browser session opened. The client in Keycloak
   must be **confidential** with the **Standard Flow** enabled and its *Valid
   redirect URIs* must contain `https://<your-host>/keycloak-callback.php`.
2. **Bearer token (reverse proxy / API client).** A request carrying
   `Authorization: Bearer <token>` is validated against `/userinfo` — use this
   when an SSO-aware reverse proxy (oauth2-proxy, Traefik ForwardAuth) or an API
   client already speaks to Keycloak.

If `KEYCLOAK_EMAIL_ACCOUNT` is set, only that specific e-mail address is allowed.

Sessions are bound to the auth method that created them, so switching `AUTH_METHOD` from `account` to `keycloak` (or back) **revokes every previously authenticated session**: the stored `fiscalhub_auth_method` no longer matches and the session is destroyed. While `AUTH_METHOD=keycloak`, the username/password form is suppressed on the login page and `login.php` refuses credentials outright, so leftover `ACCOUNT_LOGIN`/`ACCOUNT_PASSWORD` values can no longer mint a session. Logging out also triggers Keycloak's RP-initiated logout (`end_session_endpoint`) when `KEYCLOAK_CLIENT_ID` and `KEYCLOAK_REDIRECT_URI` are set.

---

## Reverse Proxy

Fiscal Hub works seamlessly behind Nginx, Traefik, Caddy, or any reverse proxy:

1. Set `PORT` in `.env` to your desired host port (container internally uses `8080`).
2. Point your reverse proxy upstream to `localhost:<PORT>`.
3. Optionally set `DOMAIN` for proxy configuration references.

**Example Nginx config:**
```nginx
server {
    listen 443 ssl;
    server_name fiscalhub.example.com;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

> **Note:** When using session-based auth (account mode) behind a reverse proxy, ensure the proxy forwards cookies correctly. No special `Authorization` header forwarding is needed since auth uses standard PHP sessions.

---

## Technology Stack

| Component | Technology |
|-----------|-----------|
| Runtime | PHP 8.5 (FPM, Alpine) |
| Frontend | Vanilla HTML5, CSS3, JavaScript (ES6+) |
| PDF Generation | pdfmake 0.2.10 |
| Barcode Scanner | Quagga2 1.8.3 |
| HTTP Client (PHP) | cURL |
| Auth (Session) | PHP sessions with `hash_equals()` credential check + optional reCAPTCHA v2 |
| Auth (SSO) | Keycloak OIDC: Authorization Code + PKCE in the browser, `/userinfo` Bearer validation via cURL |
| Containerization | Docker + Docker Compose |
| CI/CD | GitHub Actions (multi-arch build + release) |

---

## Troubleshooting

**Online lookup fails with "Server configuration error":**
- Ensure `FISCALHUB_API_KEY` is set in `docker/.env` and is a valid Meu Danfe API key.
- Restart the container after changing `.env`: `docker compose restart`

**CNPJ lookup fails:**
- BrasilAPI requires no API key. Check your outbound internet access and DNS resolution from the container.
- If BrasilAPI is rate limiting or unavailable, retry later. The CNPJ lookup is best-effort and does not block the offline XML-to-PDF feature.

**Online lookup returns 402 (no credits):**
- Your Meu Danfe account has run out of credits. Each SEFAZ query costs R$0.03. Add credits at the Meu Danfe customer area.
- As a workaround, use the "Upload XML" tab which works without any API.

**PDF generation from XML fails:**
- Ensure the file is a valid NFe XML (not a different XML type like NFSe or CT-e).
- Verify the XML contains the correct namespace: `http://www.portalfiscal.inf.br/nfe`.
- Check the browser console for specific parse errors.

**Camera scanner not working:**
- The browser must support `getUserMedia` (all modern browsers do).
- Ensure you've granted camera permissions when prompted.
- For HTTPS sites, camera access requires a secure context. If testing locally on `localhost`, this is not an issue.

**Port conflict:**
- Change `PORT` in `.env` to an available port (e.g., `8081`).

**Authentication not working:**
- Ensure `AUTH_METHOD=account` and both `ACCOUNT_LOGIN` and `ACCOUNT_PASSWORD` are set in `docker/.env`.
- If the login page redirects back to itself, check that your browser accepts cookies from the site.
- Restart the container after changing `.env`: `docker compose restart`
- reCAPTCHA: a "Falha na verificação de segurança" message means the token was missing or Google rejected it. Confirm `RECAPTCHA_SITE_KEY`/`RECAPTCHA_SECRET_KEY` are a matching **v2 checkbox** key pair and that the container can reach `www.google.com` — verification fails closed on network errors.
- Keycloak mode hides the username/password form by design: use the *Entrar com Keycloak* button, which starts the provider flow. If it reports an incomplete configuration, set `KEYCLOAK_CLIENT_ID`/`KEYCLOAK_CLIENT_SECRET` and register `https://<host>/keycloak-callback.php` (or `KEYCLOAK_CALLBACK_URI`) as a valid redirect URI on the client. If the provider answers *Invalid redirect uri* after a logout, register `KEYCLOAK_REDIRECT_URI` under *Valid post logout redirect URIs*. Old sessions from `account` mode are revoked automatically when `AUTH_METHOD` changes; clear cookies if a browser still holds a stale session.
- For Keycloak auth behind a reverse proxy: Nginx strips the `Authorization` header by default. Add `proxy_set_header Authorization $http_authorization;` to your Nginx config.

---

## Security

- **API keys are never stored in source code.** They are read from environment variables at runtime via `getenv()`.
- **The `.env` file is excluded from Docker images** via `.dockerignore`. It is only mounted at container runtime.
- **Error responses never expose internal configuration**, API keys, file paths, or stack traces.
- **The container runs as a non-root user** (`phpuser`, UID 1000).
- **Only the `webapp/` directory is copied into the image.** Docker, GitHub, and development files are excluded.
- **PHP files are executed, never served as raw text**, thanks to the PHP built-in server.

<!-- footer -->
---

## 🧑‍💻 Consulting and technical support
* For personal support and queries, please submit a new issue to have it addressed.
* For commercial related questions, please [**contact me**][ivancarlos] for consulting costs.

[cc]: https://docs.github.com/en/communities/setting-up-your-project-for-healthy-contributions/adding-a-code-of-conduct-to-your-project
[contributing]: https://docs.github.com/en/articles/setting-guidelines-for-repository-contributors
[security]: https://docs.github.com/en/code-security/getting-started/adding-a-security-policy-to-your-repository
[support]: https://docs.github.com/en/articles/adding-support-resources-to-your-project
[it]: https://docs.github.com/en/communities/using-templates-to-encourage-useful-issues-and-pull-requests/configuring-issue-templates-for-your-repository#configuring-the-template-chooser
[prt]: https://docs.github.com/en/communities/using-templates-to-encourage-useful-issues-and-pull-requests/creating-a-pull-request-template-for-your-repository
[funding]: https://docs.github.com/en/articles/displaying-a-sponsor-button-in-your-repository
[ivancarlos]: https://ivancarlos.me
