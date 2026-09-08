# Single Sign-On

SSO ist optional und standardmäßig ausgeschaltet. Beide Anmeldeseiten zeigen bei gültiger Konfiguration einen SSO-Button. Der vorhandene Kennwortzugang bleibt als Wiederherstellungsweg erhalten.

## Anbieter einrichten

Beim gewünschten OpenID-Connect-Anbieter eine vertrauliche Webanwendung mit Authorization-Code-Flow, PKCE S256, RS256-signierten ID-Tokens und Client-Secret-POST registrieren. Die Endpunkte stammen aus der Dokumentation beziehungsweise Discovery-Datei des eigenen Anbieters und werden fest in der geschützten Serverkonfiguration hinterlegt. Es gibt keine automatische Fremdserver-Erkennung anhand einer eingegebenen E-Mail-Adresse.

```dotenv
SSO_ENABLED=true
SSO_LABEL="Unternehmenskonto"
SSO_ISSUER=https://id.example.org/realms/restaurant
SSO_AUTHORIZATION_URL=https://id.example.org/realms/restaurant/protocol/openid-connect/auth
SSO_TOKEN_URL=https://id.example.org/realms/restaurant/protocol/openid-connect/token
SSO_JWKS_URL=https://id.example.org/realms/restaurant/protocol/openid-connect/certs
SSO_CLIENT_ID=platzhirsch
SSO_CLIENT_SECRET=...
```

Die Beispielstruktur entspricht Keycloak; echte URLs und Clientdaten ersetzen. Für Entra oder andere Anbieter ihre konkreten Endpunkte und den exakten mandantenspezifischen Issuer verwenden. Die Kompatibilität mit einem tatsächlichen Anbieter ist noch nicht live abgenommen.

`APP_URL` muss die echte Basisadresse der Installation sein. Im Anbieter beide Rücksprungadressen exakt freigeben:

- `https://eigene-domain/api/v1/admin/auth/sso/callback/administration`
- `https://eigene-domain/api/v1/admin/auth/sso/callback/restaurant`

Endpunkte benötigen HTTPS. Nur die lokale Rücksprungadresse erlaubt HTTP auf localhost/Loopback für Entwicklung. Geheimnisse gehören nicht in Git. Nach Änderungen Laravel-Konfigurationscache erneuern. Ein Wechsel von Issuer, Client-ID oder Endpunkten erfordert erneute Kontoverknüpfung; reine Secret-Rotation verändert die Verknüpfungen nicht.

## Eigenes Konto verknüpfen

Zuerst mit dem Platzhirsch-Kennwort anmelden. Unter Mein Konto → Single Sign-On das Kennwort und gegebenenfalls einen frischen Zwei-Faktor-Code bestätigen, dann das eigene Unternehmenskonto beim Anbieter wählen. Erst die erfolgreich geprüfte Rückmeldung verknüpft die Identitäten. Dieselbe Anbieteridentität kann nicht zwei lokalen Konten gehören. Unverknüpfte Identitäten erhalten weder automatisch ein Konto noch aufgrund gleicher E-Mail-Adresse Zugriff.

Beim späteren SSO-Login bleiben die Portalzuordnung, Kontosperren und lokalen Rollen maßgeblich. Eine lokal eingerichtete Zwei-Faktor-Anmeldung wird nach dem Anbieterlogin zusätzlich abgefragt. Die Verknüpfung kann unter Mein Konto nach erneuter Bestätigung entfernt werden.

## Technischer Prüfrahmen

Authorization-Code-Flow mit PKCE S256, kurzlebigem sitzungsgebundenem Einmal-State und Nonce. ID-Tokens werden ausschließlich mit RS256 und dem fest konfigurierten JWKS geprüft; Signatur, Issuer, Audience, gegebenenfalls Authorized Party, Ablauf, Ausstellungszeit und Nonce müssen passen. Anbieter-Token werden nicht gespeichert. Der Callback wählt den zur Rücksprungadresse gehörigen Portal-Guard und setzt No-Referrer. Standardzugriffe und API-Berechtigungen bleiben unverändert.

Diese Implementierung deckt einen festen Anbieter pro Installation ab. Keine dynamische Anbieterregistrierung, SAML, Windows-Negotiate-Anmeldung, Single Logout, automatische Gruppen-/Rollensynchronisierung oder allgemeine OIDC-Konformitätszertifizierung. Live-Abnahme benötigt die registrierte Anwendung und einen Testzugang beim eigenen Anbieter.

Grundlagen: [OpenID Connect ID Token Validation](https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation), [OAuth Authorization Code](https://www.rfc-editor.org/rfc/rfc6749.html), [PKCE](https://www.rfc-editor.org/rfc/rfc7636.html), [Keycloak-Endpunkte](https://www.keycloak.org/securing-apps/oidc-layers).
