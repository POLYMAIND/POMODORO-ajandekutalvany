# Pomo d'Oro — Ajándékutalvány rendszer

A **RESnWEB** ajándékutalvány-modulját váltó egyedi fejlesztés a Pomo d'Oro
vendéglátó-csoportnak. Négy egység (Casa, Osteria, Pizzabar, Trattoria),
egy központi, multi-tenant backend, per-egység adatizolációval.

## Aktuális állapot

A fejlesztés első fázisában a **felület kinézetén** dolgozunk (mock adatokkal,
backend-bekötés nélkül). A Supabase / fizetés bekötése egy későbbi fázisban jön.

## Repó szerkezet

```
prototype/
  admin.html            → az admin felület kattintható prototípusa (standalone,
                          böngészőben közvetlenül megnyitható, mock adatokkal)
  widget.html           → a beágyazható vásárló widget (5 lépéses folyamat),
                          egységre paraméterezve: widget.html?unit=casa
  embed-demo.html       → szimulált host weboldal, ami a widgetet iframe-ben
                          ágyazza be + a másolható beágyazó kód (nyisd meg ezt)
supabase/
  migrations/
    001_init_schema.sql        → adatbázis-séma + 4 egység + Casa címletek
    002_api_keys.sql           → CRM API-kulcsok + inkrementális szinkron (updated_at) + marketing-flag
  seed/
    placeholder_test_data.sql  → teszt-adat (CSAK teszt/staging projekten!)
  functions/
    crm-api/index.ts           → CRM olvasó API (Edge Function, API-kulcsos)
  SETUP.md                     → beüzemelés lépésről lépésre + teszthívások
```

### CRM / Supabase összekötés

A `supabase/SETUP.md` végigvezet: séma feltöltése (`supabase db push`), az
API (Edge Function) telepítése (`supabase functions deploy crm-api --no-verify-jwt`),
API-kulcs létrehozása (`select * from create_api_key(...)`) és a teszthívások.
A CRM `x-api-key` fejléccel hívja a `/crm-api/orders`, `/vouchers`, `/customers`
végpontokat; a szinkron inkrementális (`updated_since` / `cursor`), a kulcs
egységre korlátozható. A helyi PostgreSQL 16 validálta a migrációkat + seedet.

> A rendszer szigorú számadású (törlés sehol) — a placeholder seed csak
> teszt/staging projekten futtatható, éles DB-be soha.

### Widget beágyazás

A widget egy önálló oldal, amit iframe-ben lehet bármelyik weboldalba tenni:

```html
<iframe src="https://utalvany.pomodoro.hu/widget?unit=casa"
        title="Ajándékutalvány" style="width:100%;border:0"
        id="pomodoro-voucher" scrolling="no"></iframe>
<script>
  window.addEventListener("message", function (e) {
    if (e.data && e.data.type === "pomodoro-voucher" && e.data.event === "height") {
      document.getElementById("pomodoro-voucher").style.height = e.data.height + "px";
    }
  });
</script>
```

A widget `postMessage`-dzsel jelzi a magasságát, így az iframe automatikusan
hozzáigazodik (nincs belső görgetés). Éles környezetben per-egység
`frame-ancestors` korlátozza, mely domain ágyazhatja be. A működést a
`prototype/embed-demo.html` mutatja be.

### Prototípus megnyitása

Nyisd meg a `prototype/admin.html` fájlt bármelyik böngészőben — nincs szükség
build lépésre vagy szerverre.

A prototípus tartalma:
- **Egységváltó** (Casa / Osteria / Pizzabar / Trattoria) fent.
- **Utalványok** fül: állapot-összesítő, keresés + szűrés (státusz, címlet),
  utalványlista (legacy RESnWEB és új sorszámú tételekkel), „Új utalvány" űrlap.
- **Címletek** fül: címletek egyedi szerkesztése (összeg, megnevezés, ki/be).
- **Beállítások** fül: egységközi beváltás kapcsoló, érvényességi idő,
  sorszám-előtag, cég adatai.

## Következő lépések

1. A kinézet véglegesítése (visszajelzés alapján).
2. Portolás valós frontendre (React + TypeScript), a fenti prototípus alapján.
3. Supabase bekötés (a séma alapján).
4. RESnWEB CSV importer (idempotens, legacy ID-megtartással, per-egység).
5. Fizetés (SimplePay / Stripe) + PDF + email.
6. Beágyazható vásárló widget + kasszás beváltó nézet.

## Megjegyzés az importhoz

A kapott minta export **10 oszlopos** (Azonosító, Státusz, Vásárlás dátuma,
Számlázási név, Megajándékozott neve, Lejárat, Érték, Email, Vendég megjegyzése,
Szálláshely megjegyzése) — ez tér el a projekt-átadóban leírt 48 oszlopos
NAV-formátumtól. Az importer a valós, 10 oszlopos fájlhoz igazodik majd; a
NAV-export ettől külön, előállítandó formátum.
