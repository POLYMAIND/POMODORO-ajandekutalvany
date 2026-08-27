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

A lista **keresője** a vásárló nevére, sorszámra, e-mailre, telefonra, valamint a
műveletet végző kezelő **nevére és belépési e-mail-címére** szűr (beváltó és
visszaállító egyaránt) — a kezelőből ezért a nevet és az e-mailt is eltároljuk.
A nagy összesítő marad az időszak teljes összege (ez a kasszazárás száma) — a
kereséshez tartozó darabszám/összeg a lista fejlécében jelenik meg.

**Téves beváltás visszaállítása:** a beváltott sor végén (és a keresőből nyíló adatlapon)
a *Visszaállítás* gomb aktívra állítja vissza az utalványt (`POST /api/unredeem`).
Jogosultság: aki az egységet láthatja (kasszás is) — naplózott, de nem külön
engedélyhez kötött.

**Visszaállított (téves) beváltások** — külön táblázat ugyanezen a fülön, ugyanarra az
időszakra: mikor, melyik egység, sorszám, érték, ki állította vissza, mi volt az
**eredeti beváltás** (időpont + ki váltotta be), és mi a tétel **jelenlegi állapota**.
A kártya akkor is látszik, ha nem volt visszaállítás. Ha volt, az összesítőben is
megjelenik egy kiemelt „Visszaállítva: N db · X Ft” érték — a visszaállított tétel
ugyanis kikerül a beváltott listából, e nélkül a napi összeg némán csökkenne.

Minden beváltás és visszaállítás bekerül a `pgv_voucher_log` táblába (ki, mikor,
mennyit; visszaállításnál a felülírt `prev_redeemed_at` / `prev_redeemed_by` is).
A `/api/data` innen csak az `unredeem` sorokat adja vissza (365 nap), hogy a 8
másodperces poll könnyű maradjon — a beváltás-sorok auditra a táblában maradnak.

A kasszán történt beváltást a bolt plugin push-a nem írhatja vissza „aktív”-ra
(`redeemed_via = 'cockpit'` őrfeltétel az upsertben), különben egy `sync_all` csendben
eltüntetné a napi beváltásokat.

## Import / export

Az import **sorszám szerint azonosít** (`unit` + `utalvány kódja` az elsődleges kulcs),
ezért ugyanaz a tétel nem duplikálódik: a meglévő sor **felülíródik** a fájl adataival,
csak a valóban új sorszámok kerülnek be újként. A visszajelzés külön írja ki, mennyi
volt új és mennyi frissült. Sorszám nélküli sor kimarad (a „kihagyva” számban látszik).

Két dolgot az import nem írhat felül:
- a **vezérlőpulton beváltott** utalvány állapotát (`redeemed_via = 'cockpit'`),
- egy **élő, boltból push-olt** utalvány `is_legacy` jelzését — így az „Import
  visszavonása” (ami az egység legacy sorait törli) nem viheti el őket.

Ugyanaz a fájl **másik egységbe** importálva külön tételeket hoz létre (az egység a
kulcs része) — ez szándékos, de figyelni kell rá.

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
