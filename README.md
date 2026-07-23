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
supabase/
  migrations/
    001_init_schema.sql → az adatbázis-séma (Postgres/Supabase), a projekt-átadóból
```

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
