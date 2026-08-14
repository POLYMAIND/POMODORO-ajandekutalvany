# Pomo d'Oro — Központi vezérlőpult (cockpit)

Statikus UI (`index.html`) + Vercel serverless API (`api/*.js`), ami szerveroldalon
összegzi a 4 bolt adatait (a WordPress plugin `pgv/v1` API-járól). Nincs saját
adatbázis: közel valós idejű pollinggal frissül. Egységes kassza a `/api/redeem`-en.

## Környezeti változók (Vercel → Settings → Environment Variables)
- `APP_PASSWORD` — belépési jelszó a vezérlőpulthoz.
- `AUTH_SECRET` — hosszú véletlen string a süti-aláíráshoz.
- `SHOPS` — a boltok JSON tömbje:
  ```json
  [
    {"slug":"casa","name":"Casa Pomo d'Oro","prefix":"CASA","url":"https://casa.pomodorobudapest.com","apiKey":"pk_..."},
    {"slug":"osteria","name":"Osteria Pomo d'Oro","prefix":"OST","url":"https://...","apiKey":"pk_..."}
  ]
  ```
  Az `apiKey` a plugin Beállítások oldalán generált CRM API-kulcs.

Root directory a Vercel projektben: `cockpit`.
