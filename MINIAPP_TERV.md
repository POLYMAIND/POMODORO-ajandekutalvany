# Pomo d'Oro — Központi Utalvány-vezérlőpult (mini-app) · Terv

Központi mini-app a **saját domainen**, ami mind a 4 egység (Casa/Osteria/Pizzabar/
Trattoria) fölött ül: figyeli a vásárlásokat és lejáratokat, egy helyen szerkeszthető
az utalvány kinézete, egységes kassza, minden egységre lebontva.

Döntések (a megrendelővel egyeztetve):
- **Hibrid**: a WooCommerce-boltok maradnak a bolt (eladás + sorszám + könyvelés,
  cégenként). A mini-app a központi vezérlőpult.
- **Next.js + Supabase (Postgres), Vercelen**, saját domainre kötve.
- **Egységes kassza** a mini-appban (sorszám-alapú beváltás, központi napló).

## Architektúra

```
[4× WooCommerce bolt]  --(webhook: fizetés/kibocsátás/beváltás)-->  [Mini-app API]
        ^   |                                                              |
        |   +--(kinézet-config lekérés: a PDF-hez)--------------------------+
        |                                                                   v
        +--(kassza-beváltás visszaírás)----------------------------  [Supabase Postgres]
                                                                            ^
                                                                     [Next.js UI a domainen]
```

- **Adat**: a mini-appnak saját Postgres-e van (Supabase). Ide gyűlik minden egység
  vásárlása/utalványa/státusza/lejárata → gyors kereszt-egység lista, lejárat-figyelés,
  összesítés. Feltöltés: (a) a WP-plugin webhookja fizetéskor (`pgv_voucher_issued`),
  (b) időzített pull a már meglévő `/wp-json/pgv/v1/...` olvasó API-ról (biztonsági háló,
  idempotens upsert `serial`/`order_ref` kulcsra).
- **Kinézet**: a mini-appban szerkeszted egységenként (szín, cím, szövegek, kép, logó).
  A kiküldött PDF ezt használja: a WP-plugin a fizetéskor lekéri a mini-apptól az adott
  egység aktuális kinézet-configját, és azzal rendereli a PDF-et (a meglévő
  `pgv_use_builtin_pdf` / config-hook pontokon).
- **Kassza**: a mini-app sorszámra keres a saját DB-jében, beváltja, és **visszaírja** az
  adott bolt WooCommerce-utalványának státuszát (a plugin új, hitelesített
  `redeem` végpontján) → a cégenkénti könyvelés is naprakész.
- **Jogosultság**: bejelentkezés (Supabase Auth); szerep szerint teljes (group_admin)
  vagy egységre szűkített (unit_admin/cashier) hozzáférés.

## Felület (a prototípus szerint)
- **Áttekintés**: KPI-k (eladott db, bevétel, aktív, hamarosan lejáró), legutóbbi
  vásárlások, eladási görbe, közelgő lejáratok — egységre szűrve.
- **Vásárlások**: teljes lista, szűrés egység/státusz/kereső szerint, export.
- **Lejáratok**: sürgősség szerint, emlékeztető-küldés.
- **Utalvány kinézet**: élő szerkesztő + előnézet, egységenként.
- **Kassza**: sorszám → beváltás, központi napló.
- **Egység beállítások**: cég/adószám/előtag/érvényesség/feladó, egységenként.

## A WP-pluginon szükséges kiegészítések (a mini-apphoz)
1. **Kimenő webhook** kibocsátáskor/beváltáskor/státuszváltáskor → mini-app API (aláírt).
2. **Kinézet-config lekérés** a mini-apptól a PDF rendereléséhez (cache-elve).
3. **Beváltás-végpont** (hitelesített), hogy a mini-app kassza visszaírhassa a státuszt.
Ezek a már meglévő hook/REST alapokra épülnek (`pgv_voucher_issued`, `pgv/v1`, filterek).

## Ütemterv (fázisok)
1. **Prototípus** (kész, kattintható) — UX/lát­vány egyeztetés. ✔
2. **Váz + adatmodell**: Next.js app + Supabase séma (a meglévő `supabase/` migrációkra
   építve), auth, egységváltó, üres nézetek.
3. **Szinkron**: webhook-fogadó + időzített pull a boltokból; valós adat a listákban.
4. **Kinézet-szerkesztő → PDF**: config tárolása + a WP-plugin lekéri és rendereli.
5. **Kassza visszaírás** + lejárat-figyelő értesítők (cron).
6. **Domainre kötés + éles**: Vercel projekt, egyedi domain, Supabase éles projekt.

## Amit a megrendelőtől kérünk a valós telepítéshez
- **Supabase** projekt (vagy jóváhagyás, hogy hozzunk létre) — kapcsolat/kulcsok.
- **Vercel** hozzáférés + a **domain** (vagy aldomain, pl. `utalvany.pomodorobudapest.com`).
- A 4 bolt **WooCommerce REST kulcsai** + a plugin CRM API-kulcsai (már generálódik).
