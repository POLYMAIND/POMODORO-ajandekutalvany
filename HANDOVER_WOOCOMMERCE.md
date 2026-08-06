# Pomo d'Oro — Ajándékutalvány rendszer · Projekt-átadó (WooCommerce irány)

> Ez a dokumentum összefoglalja a projekt célját és **az eddig megfogalmazott
> összes elvárást**, hogy átvihető legyen egy új, **WooCommerce-alapú** fejlesztésbe.
> Az eredeti prototípus (egyedi app + Supabase) **referenciaként** marad meg: a
> követelmények, a UX és az export-formátum onnan 1:1 átemelhető.

---

## 1. Mi ez a projekt

A **RESnWEB** ajándékutalvány-modulját váltja le a **Pomo d'Oro** vendéglátó-
csoportnál. Online eladható, **személyre szabható** ajándékutalvány, saját admin-
és kasszafelülettel, NAV-kompatibilis kimutatással, a csoport CRM-jével összekötve.

**Négy egység — mindegyik külön cég (külön adószám) és külön WordPress-oldal:**

| slug | név | sorszám-prefix | címletek (Ft) |
|------|-----|----------------|----------------|
| `casa` | Casa Pomo d'Oro | `CASA` | 30 000 / 35 000 / 40 000 / 50 000 |
| `osteria` | Osteria Pomo d'Oro | `OST` | 30 000 / 40 000 / 50 000 |
| `pizzabar` | Pizzabar Pomo d'Oro | `PIZ` | 25 000 / 30 000 / 40 000 |
| `trattoria` | Trattoria Pomo d'Oro | `TRA` | 25 000 / 30 000 / 40 000 / 50 000 / 100 000 |

## 2. Az ÚJ architektúra-irány (WooCommerce)

- Az utalványokat **WooCommerce termékként** hozzuk létre (címletenként egy termék,
  vagy változó termék árváltozatokkal), egységenként.
- A személyre szabást, adatbekérést, képválasztást stb. egy **egyedi WordPress
  plugin** oldja meg, amely **felülírja a kosár/checkout oldalt**.
- Mivel **4 külön cég** (külön adószám, külön SimplePay, külön számlázás), és már
  most is **4 külön WP-oldal** van → kézenfekvő, hogy **egységenként külön
  WooCommerce** fut a saját WP-oldalán; a plugin mind a négyre települ.
- A CRM-kapcsolathoz a **WooCommerce beépített REST API-ja + webhookjai**
  használhatók (orders/customers készen jön) — nem kell külön adat-API-t építeni.

## 3. Funkcionális követelmények (a vásárlói oldalon)

A checkout-felülíró plugin ezeket gyűjtse be / támogassa:

1. **Fix címletű** utalványok (mint a jelenlegi rendszerben), egységenként.
2. **Címletenkénti termékkép** — minden címlethez beállítható, melyik kép a terméke;
   a vásárlónál ez jelenik meg a kártyán.
3. **Személyre szabás:**
   - **utalványkép választása** egy per-egység képkészletből (a képeknek **neve** van),
   - **megajándékozott neve**,
   - **egyedi üzenet** a megajándékozottnak.
4. **Mennyiség** (több darab; mindegyik külön sorszámot kap).
5. **Kézbesítés módja:**
   - „a megajándékozottnak" → ekkor **bekérjük a megajándékozott e-mail címét** és
     lehet **egyedi üzenetet** írni neki,
   - vagy „nekem küldjétek" (a vevő adja át).
6. **Pénznem-választó** — több pénznem, **HUF az alap**, a többi tájékoztató
   árfolyamon; adminban állítható, mely pénznemek elérhetők és megjelenjen-e a választó.
   (A jelenlegi rendszerben is volt pénznem-választó.)
7. **Nyelvválasztó** — a felület feliratai fordulnak (min. **HU/EN/DE/IT**); adminban
   állítható, mely nyelvek elérhetők (a magyar az alap). Az egyedi szövegek
   (fejléc/bevezető/feltételek) nyelvenkénti megadása kívánatos.
8. **Cégnév / adószám élő figyelmeztetés** — ha a vásárló Kft/Bt/Zrt/Nyrt-t vagy
   adószámot (hosszú számsor) ír bármelyik mezőbe → figyelmeztetés, hogy **áfás számla
   ezen a felületen nem igényelhető**. Kétszintű: kliens (regex) + szerver-ellenőrzés.
9. **Marketing-hozzájárulás** — opcionális checkbox („szeretnék értesülni akciókról");
   GDPR-barát, külön hozzájárulás; ez az adat menjen az exportba és a CRM-be.
10. **Arculati testreszabás egységenként** (a widget/checkout megjelenése):
    **kiemelő szín**, **sarok-lekerekítés (border-radius, a 0 legyen tényleg 0)**,
    **betűtípus**, **fejléc cím**, **bevezető szöveg**, **beváltási feltételek**,
    **céges érdeklődés e-mail**.

## 4. Elvárások az ADMIN felülettel

- **Egyszerű, letisztult** (referencia: a Polymaind „Szállítói számla felismerő"
  stílusa — fehér kártyák, coral/tomato akcentus, sok fehér tér). Ne legyen
  bonyolultabb a mostaninál.
- **Egységváltó** (Casa/Osteria/Pizzabar/Trattoria) — WooCommerce esetén ez lehet
  egységenkénti külön admin (külön WP-oldal), vagy központi nézet.
- **Utalványlista** szűréssel/kereséssel (sorszám, név, e-mail; státusz; címlet).
- **Címlet-szerkesztés** egyedileg (összeg, megnevezés, ki/be) + **termékkép-
  hozzárendelés** címletenként.
- **Képkészlet egységenként**, a képek **elnevezhetők**.
- **Általános (csoportszintű) beállítások** + azon felül a **külön egységek**
  beállításai (cégnév, adószám, sorszám-előtag, aktív, számlázás-összekötés, képek).
- **Widget/checkout-szerkesztő egységenként** (arculat, szövegek) **élő előnézettel**,
  amely **végigkattintható** (a teljes vásárlási folyamat), és a beállítások
  (szín/radius/betű/szöveg) **azonnal frissülnek**.
- **CSV-export** = a NAV-kimutatás (lásd 6. pont).
- **NEM** kell kézi utalvány-felvitel az adminban — az utalványt a **vásárló hozza
  létre** a vásárlással. (Kézi/„ajándék" kiállítás legfeljebb ritka extra.)
- **Per-egység számlázás-összekötés** (Számlázz.hu / Billingo) — minden cég a saját
  fiókjával.

## 5. Szigorú számadás (könyvelői / NAV elvárás)

- **Egyedi sorszám** utalványonként. Igény szerint **hézagmentes** per-egység + év
  sorszám (`CASA-2026-000042`). *Nyitott: a hézagmentesség jogilag kötelező-e, vagy
  elég az egyedi ID — könyvelői döntés.*
- **Teljes audit napló** minden státuszváltozásról; **törlés sehol** — a sztornó is
  csak `cancelled` státusz + auditsor.
- **Kétféle sorszám:** az importált **legacy** azonosító változatlanul megmarad
  (`is_legacy`), az **új** utalvány prefixes sorszámot kap. Mindkettő beváltható.
- **A sorszám csak fizetéskor születik** (a fizetési visszaigazolásból/webhookból),
  soha nem a kliensoldali „siker" redirectből → a szekvencia hézagmentes.
- **Érvényesség 1 év** a vásárlástól, egységenként állítható.
- **Ki, mit, mikor vásárolt, mikor fizette, mikor váltották be** — kimutatható.

## 6. Export / NAV-formátum

A jelenlegi RESnWEB-export **1:1 követendő** (importhoz és NAV-kimutatáshoz):
- **Elválasztó `;`**, minden mező `"` idézőjelben, **UTF-8 BOM**, **CRLF** sorvég.
- **Összeg** egész, tagolás nélkül; dátumok: teljes időpont ill. csak dátum (oszloptól függ).
- A minta-export **10 oszlopos** volt (Azonosító; Státusz; Vásárlás dátuma; Számlázási
  név; Megajándékozott neve; Lejárat; Érték; Email; Vendég megjegyzése; Szálláshely
  megjegyzése) — de a **kívánt export** (megbeszélt bővítés) oszlopai:
  **Azonosító · Státusz · Vásárlás dátuma · Fizetés dátuma · Felhasználás dátuma ·
  Számlázási név · Megajándékozott neve · Email · Marketing feliratkozás · Lejárat ·
  Érték · Vendég megjegyzése**. (A „Szálláshely megjegyzése" nem kell.)

## 7. Fizetés

- **SimplePay** az elsődleges (a jelenlegi rendszer is ezt használja, és **SZÉP-kártyát
  is** tud — a Stripe nem). WooCommerce-hez van hivatalos **OTP SimplePay** bővítmény.
- **Egységenként külön SimplePay-szerződés/merchant** (`payment_config` / a Woo-plugin
  beállításai egységenként). A fizetési réteg legyen cserélhető.
- **A sorszám a sikeres fizetés után** jön létre (Woo: `woocommerce_payment_complete`
  / `woocommerce_order_status_completed` hook), nem a redirectből.
- Tisztázandó: van-e már működő SimplePay-fiók mind a négy cégnél.

## 8. E-mail + PDF kézbesítés

- **Tranzakciós e-mail** kell (nem marketing): az utalvány **PDF-ben** (kép + üdvözlő
  szöveg + megajándékozott + sorszám + **QR-kód**), plusz visszaigazolás.
- **Saját domainről**, **SPF/DKIM/DMARC**-kal (kézbesíthetőség), **per-egység feladóval**.
- Lehetőség: a Pomo d'Oro **meglévő rendszere** (HTML-űrlap → PDF-generálás + emailküldés)
  **API-n** átveszi a kész HTML-t és kiküldi — így nem kell újraépíteni. Alternatíva:
  WooCommerce PDF-voucher/gift-card plugin + tranzakciós e-mail szolgáltató.
- Legyen **újraküldés** az adminból.

## 9. Számlázás (Számlázz.hu)

- **Automatikus bizonylat** fizetés után; WooCommerce-hez van **Számlázz.hu bővítmény**.
- **4 külön cég = 4 külön Számlázz.hu fiók** (egységenkénti agent-kulcs, titkosítva).
- **Áfa-logika / könyvelői döntés:** a hotel/vendéglátó utalvány jellemzően **többcélú
  utalvány** → **kibocsátáskor nincs áfa**, az áfa a **beváltáskor** keletkezik → ezért
  vásárláskor nincs áfás számla. Tisztázandó a könyvelővel: vásárláskor **mit** állítson
  ki a rendszer (nyugta / e-nyugta / díjbekérő / semmi).

## 10. CRM-összekötés (a csoport saját CRM-je)

- Cél: vásárlók listázása, **szegmentálás** (melyik egységbe vásárolt, mikor, mennyiért,
  beváltotta-e, marketing-hozzájárulás).
- WooCommerce-nél a **beépített REST API** (orders, customers) + **webhookok** (order
  paid/completed) közvetlenül használhatók a CRM-ből — API-kulccsal (WooCommerce
  consumer key/secret), **egységenként** (mind a 4 store).
- **Inkrementális szinkron** (módosítás dátuma szerint), **idempotens** upsert a
  rendelésazonosítóra.
- **GDPR:** marketinghez csak a hozzájárulást adott vevők.

## 11. Legacy import + átállás (cutover)

- **RESnWEB CSV importer** (a 6. pont formátuma), **idempotens** (kulcs az `azonosító`-ra),
  a **legacy ID-t megtartva**, **egységenként** (a Trattoria fájlja → Trattoria).
  Státusz-leképezés: `fizetve` → aktív, `felhasználva` → beváltott (a dátummal).
- **Cutover:** RESnWEB-en cutoff → egységenkénti export → import (ID-megtartással) → a
  vásárlási űrlapok átkapcsolása. A kassza **egy felületen** lássa a cutoff előtti és
  utáni utalványt is.

## 12. Kasszás nézet

- **Sorszám-keresés / QR-beolvasás → egy-kattintásos beváltás**, egységre szűrve.
- Csak **aktív** utalvány váltható be; lejárat-ellenőrzés; a beváltás **auditba** kerül.
- Legacy és új sorszám egyaránt beváltható.

## 13. Mi készült el ebben a fázisban (referenciaként átvehető)

A repóban (az eredeti, egyedi-app irányból) — **specifikációként/UX-referenciaként**
hasznos a Woo-fejlesztéshez is:

- `supabase/migrations/001_init_schema.sql` — **adatmodell-referencia**: unit, app_user,
  voucher_image, voucher_denomination, serial_counter, voucher_order, voucher,
  voucher_audit; sorszám-allokálás; audit-trigger; beváltás; RLS. (A Woo-nál ez
  order/product meta + esetleg egyedi tábla formában képződik le.)
- `prototype/admin.html` — **admin UX/vizuál** (egységváltó, utalványlista+szűrés,
  címlet+termékkép, képkészlet elnevezéssel, általános + egység-beállítások,
  widget-szerkesztő élő előnézettel, CSV-export).
- `prototype/widget.html` + `prototype/embed-demo.html` — a **vásárlói folyamat UX-e**
  (5 lépés, arculat, pénznem, nyelv, cégnév-figyelmeztetés, marketing-checkbox).
- `supabase/CRM_API.md` — a **CRM-integráció adatmezői/logikája** (WooCommerce REST-re
  átültethető: milyen mezők, szegmentálás, inkrementális szinkron).
- Export-formátum leírása (6. pont).

## 14. Hogyan képződik le WooCommerce-re (gyors térkép)

| Igény | WooCommerce megoldás |
|-------|----------------------|
| Egység (külön cég/adószám/fizetés) | **külön WooCommerce store** a 4 WP-oldalon |
| Címletek | **termékek** (vagy változó termék árváltozatokkal) egységenként |
| Adatbekérés, képválasztás, üzenet | **egyedi plugin**, ami felülírja a **kosár/checkout** oldalt; az adatok **order item meta**-ba |
| Termékkép címletenként | termék galéria / kép a terméken; kiválasztott kép az order metába |
| Sorszám + audit | generálás **fizetés után** (`woocommerce_payment_complete`); meta vagy egyedi tábla + naplózás |
| PDF + QR + e-mail | Woo PDF-voucher plugin **vagy** a meglévő HTML→PDF+email rendszer API-n; QR a sorszámból |
| Pénznem-választó | multicurrency plugin (pl. Aelia / WooCommerce Multilingual) |
| Nyelv | WPML / Polylang |
| Cégnév-adószám figyelmeztetés | a plugin kliens+szerver validációja a checkouton |
| Marketing-hozzájárulás | checkout checkbox → order meta → CRM |
| CSV/NAV export | egyedi admin export (a 6. pont formátumában) |
| Számlázás | Számlázz.hu WooCommerce plugin, egységenként |
| CRM | WooCommerce **REST API + webhookok**, egységenként, consumer key/secret |
| Kasszás beváltás | egyedi admin oldal/endpoint (sorszám/QR → beváltás + audit) |

## 15. Nyitott kérdések / döntések (vidd tovább)

- **Fizetés:** van-e már SimplePay-szerződés mind a 4 cégnél? (SZÉP-kártya miatt SimplePay.)
- **Hézagmentes sorszám** kell, vagy elég az egyedi ID? — könyvelő.
- **Vásárláskor milyen bizonylat** (nyugta/e-nyugta/semmi)? — könyvelő (többcélú utalvány áfa).
- **Céges adatokat** gyűjtsünk-e, vagy csak figyelmeztetés?
- **Lejárt, be nem váltott** utalvány kezelése — könyvelő.
- **Kell-e csoportszintű** (mind a 4 egységet összesítő) áttekintő? (Woo-nál külön store-ok → külön adat; összesítéshez a CRM a jó hely.)
- **PDF+email**: a meglévő rendszer API-ja vagy Woo-plugin?
- **4 külön store** vs. egy közös — a külön cég/adószám/fizetés miatt a külön store a valószínű.
