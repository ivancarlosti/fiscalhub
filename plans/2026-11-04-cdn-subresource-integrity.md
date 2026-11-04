# Plan - SRI on the CDN resources of the web app

> Task: close the two `js/functionality-from-untrusted-source` alerts reported by
> CodeQL against `webapp/index.html` (pdfmake and its font bundle).

## 1. Context

| Alert | Rule | Severity | Location | Message |
|---|---|---|---|---|
| 1 | `js/functionality-from-untrusted-source` | medium | `webapp/index.html:445` | script loaded from a CDN with no integrity check |
| 2 | `js/functionality-from-untrusted-source` | medium | `webapp/index.html:446` | script loaded from a CDN with no integrity check |

The page loads four cross-origin resources by version but without an integrity
hash: Font Awesome (`6.5.1`, `index.html` + `login.html`), Quagga2 (`1.8.3`),
pdfmake (`0.2.10`) and its `vfs_fonts` bundle (`0.2.10`). A compromised CDN, a
DNS hijack of the CDN host or a swapped file executes arbitrary JavaScript in an
origin that holds the session cookie of the app - the whole point of
`functionality-from-untrusted-source`.

## 2. Goal

Every CDN reference is pinned **and** integrity-checked, so a tampered file is
refused by the browser instead of executed: alerts 1 and 2 close as `fixed` and
no new one appears for the other two resources.

## 3. Steps

1. Compute the `sha384` digest of each pinned file, over the identity bytes
   (validated to be identical when fetched with `Accept-Encoding: gzip` and
   decoded by the client):

   ```bash
   curl -s <url> | openssl dgst -sha384 -binary | openssl base64 -A
   ```

   | Resource | SHA-384 |
   |---|---|
   | `cdn.jsdelivr.net/npm/@ericblade/quagga2@1.8.3/dist/quagga.min.js` | `i03FhjBudgoiI9QHNKl5k6Kg5sC/s+r0+4ZZMWpPJNipj9Xp3VzBi5ha7f6B3DIc` |
   | `cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.10/pdfmake.min.js` | `vseuUuO/VRMn00lXYd5m/Pk4l/7RjMqhXGf6G0RALy+On0txYb39dQsUQDAd7q5L` |
   | `cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.10/vfs_fonts.min.js` | `nXUiXap6sIVQQCtbYdJPsTOTJwv3rZjOdv8Ckh+3udRCVp3YLpdgHnjpPHD/LaC0` |
   | `cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css` | `t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g` |

2. Add `integrity="sha384-..."` and `crossorigin="anonymous"` to the four tags
   (`webapp/index.html` scripts + stylesheet, `webapp/login.html` stylesheet).
   Both CDNs answer `Access-Control-Allow-Origin: *`, which SRI requires for a
   cross-origin fetch.
3. Document the recompute step in the comment above the script block, so the
   next version bump does not silently drop the attribute.

## 4. Risks

- **A version bump without a new hash breaks the page** (the browser refuses the
  resource). Mitigation: the comment in `index.html` spells out the command, and
  both versions live in the URL next to the hash.
- **SRI has no effect on `data:`/same-origin files** - not used here.
- The `flagcdn.com` images are content, not executable, and carry no integrity
  attribute on purpose (they are not covered by the query, and pinning them would
  break the flag set).

## 5. Verification

```bash
grep -n 'integrity=' webapp/index.html webapp/login.html   # 4 + 1 hits
curl -sI https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.10/pdfmake.min.js \
  | grep -i access-control-allow-origin
```

After the push: wait for the CodeQL run and confirm alerts 1 and 2 are `fixed`
and that no new `js/functionality-from-untrusted-source` appeared.
