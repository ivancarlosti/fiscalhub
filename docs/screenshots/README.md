# Screenshots

The images in this directory are real captures of Fiscal Hub, taken by
`capture.mjs` from locally running containers. They document the four input
tabs, both themes, the two login gates and the mobile layout.

| File | Screen |
| --- | --- |
| `01-upload-xml-light.png` | Upload de XML tab, light theme |
| `02-consulta-chave.png` | Consulta por chave, 44-digit key filled in |
| `03-consulta-nfe.png` | Consulta NF-e, municipal directory |
| `04-consulta-cnpj.png` | Consulta CNPJ, public record from BrasilAPI |
| `05-upload-xml-dark.png` | Upload de XML tab, dark theme |
| `06-login-account.png` | `AUTH_METHOD=account` login form |
| `07-login-keycloak.png` | `AUTH_METHOD=keycloak` single sign-on gate |
| `08-mobile.png` | Upload de XML tab at 390x844 |

## Regenerating

```sh
docs/screenshots/generate.sh
```

The script builds `fiscalhub:screenshots`, boots three throwaway containers
(`none` on 8080, `account` on 8081, `keycloak` on 8082), captures eight PNGs at
1440x900 with a device pixel ratio of 2, and removes the containers again —
also when a capture fails. Override the ports with `APP_PORT`, `ACCOUNT_PORT`
and `KEYCLOAK_PORT`, or reuse an already built image with
`IMAGE=... SKIP_BUILD=1`.

Chrome writes truecolor PNGs; when Pillow is installed the script reduces them
to a 256-colour adaptive palette (~60% smaller, visually identical), which keeps
the committed images and the README light.

Requirements: Docker, Node.js 22 or newer (global `fetch`/`WebSocket`), and
Chrome or Chromium (`CHROME_BIN` points at a specific binary). A running
instance is fine as well:

```sh
SCREENSHOT_BASE_URL=https://fiscalhub.example.com \
SCREENSHOT_ACCOUNT_URL=https://fiscalhub.example.com \
SCREENSHOT_KEYCLOAK_URL=https://fiscalhub.example.com \
node docs/screenshots/capture.mjs
```

The CNPJ capture queries `33.000.167/0001-01` (Petrobras) through BrasilAPI,
so it needs outbound network access and renders the same public data anyone
would get from the app. The access key in `02-consulta-chave.png` is an
illustrative 44-digit value; no document is uploaded and no credentials or
private data appear in any image.
