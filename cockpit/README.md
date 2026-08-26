# Pomo d'Oro — Központi vezérlőpult (cockpit)

Statikus UI (`index.html`) + Vercel serverless API (`api/*.js`), ami szerveroldalon
összegzi a 4 bolt adatait (a WordPress plugin `pgv/v1` API-járól). Nincs saját
adatbázis: közel valós idejű pollinggal frissül. Egységes kassza a `/api/redeem`-en.

## Kassza – beváltás

Két fül:

- **Beváltás** — élő kereső (név / e-mail / sorszám) → adatlap → egy kattintásos beváltás.
- **Beváltott utalványok** — csak a felhasznált utalványok, a **beváltás dátuma** szerint
  szűrve (Ma / Tegnap / 7 nap / Ez a hónap / Összes, vagy egyedi időszak), darabszám- és
  összeg-összesítővel (egységenkénti bontással is). Ez a nap végi kasszazáráshoz kell —
  nem kell hozzá exportálni.

**Téves beváltás visszaállítása:** a beváltott sor végén (és a keresőből nyíló adatlapon)
a *Visszaállítás* gomb aktívra állítja vissza az utalványt (`POST /api/unredeem`).
Minden beváltás és visszaállítás bekerül a `pgv_voucher_log` táblába (ki, mikor, mennyit),
és a visszaállítások az adott időszak alatt külön listában is megjelennek, hogy az
összesítő utólag is ellenőrizhető maradjon. Jogosultság: aki az egységet láthatja
(kasszás is) — a visszaállítás naplózott, de nem külön engedélyhez kötött.

A kasszán történt beváltást a bolt plugin push-a nem írhatja vissza „aktív”-ra
(`redeemed_via = 'cockpit'` őrfeltétel az upsertben), különben egy `sync_all` csendben
eltüntetné a napi beváltásokat.

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
