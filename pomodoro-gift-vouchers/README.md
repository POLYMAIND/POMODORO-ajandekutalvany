# Pomo d'Oro — Ajándékutalvány (WooCommerce plugin)

Ajándékutalványok értékesítése **WooCommerce termékként**: a plugin a termék-/kosár-/
checkout oldalon begyűjti a személyre szabást, a **sikeres fizetés után** egyedi,
hézagmentes sorszámmal létrehozza az utalványokat, szigorú számadású audit naplót vezet,
kasszás beváltást, NAV-formátumú CSV export/importot és CRM olvasó API-t ad.

> Egy store = egy egység. A négy egység (Casa / Osteria / Pizzabar / Trattoria) **külön
> WordPress-oldalon, külön WooCommerce-szel** fut; a plugin mind a négyre telepítendő,
> a **Beállítások** oldalon az adott egységre állítva (slug, előtag, cégnév, adószám stb.).

## Telepítés

1. Másold a `pomodoro-gift-vouchers/` mappát a `wp-content/plugins/` alá (vagy zip-ből töltsd fel).
2. Aktiváld a plugint (aktiváláskor létrejönnek a táblák).
3. **Ajándékutalvány → Beállítások**: állítsd be az egységet, sorszám-előtagot, érvényességet, feladó e-mailt, marketing feliratot.
4. Hozz létre a címleteknek **WooCommerce termékeket**, és a termék szerkesztőben pipáld be az **„Ajándékutalvány"** jelölőt (Termékadatok → Általános). Az ár a címlet, a termék neve a megnevezés, a termékkép a címlet képe (ezt a téma jeleníti meg).
5. **Ajándékutalvány → Képkészlet**: töltsd fel és **nevezd el** a választható utalványképeket.

## Mit ad a plugin (a HANDOVER pontjaihoz kötve)

| HANDOVER pont | Megvalósítás |
|---|---|
| 3.1 Fix címletű utalvány, egységenként | WooCommerce termék + „Ajándékutalvány" jelölő |
| 3.2 Címletenkénti termékkép | a termék saját képe (téma jeleníti meg) |
| 3.3 Személyre szabás (kép/megajándékozott/üzenet) | termékoldali mezők (`PGV_Cart`), elnevezett per-egység képkészlet (`PGV_Images`) |
| 3.4 Mennyiség → külön sorszám | fizetéskor a mennyiség szerint külön-külön sorszámú utalvány jön létre |
| 3.5 Kézbesítés (megajándékozott/vevő) + e-mail | kézbesítés-rádió + feltételes megajándékozotti e-mail, validációval |
| 3.6 Cégnév/adószám élő figyelmeztetés | kliens-regex (`frontend.js`) + szerver-ellenőrzés (`PGV_Corporate` + checkout) |
| 3.7 Marketing-hozzájárulás | checkout checkbox → rendelés-meta → CRM (`marketing_opt_in`) |
| 5. Szigorú számadás | hézagmentes per-egység+év sorszám, teljes audit napló, törlés sehol, sztornó = `cancelled` + audit |
| 5. Sorszám fizetéskor | `woocommerce_payment_complete` / `_completed` / `_processing` — idempotensen |
| 5. Legacy + új sorszám | `is_legacy` jelölés; mindkettő beváltható |
| 6. NAV CSV export | `;` elválasztó, minden mező `"`-ben, UTF-8 BOM, CRLF; a megbeszélt 12 oszlop |
| 8. E-mail + PDF/QR | tranzakciós e-mail + `pgv_voucher_issued` hook a külső HTML→PDF rendszernek; QR token minden utalványon; adminból újraküldés |
| 10. CRM | WooCommerce beépített REST + a plugin `/wp-json/pgv/v1/{vouchers,orders,customers}` olvasó API-ja, API-kulccsal, inkrementális szinkronnal |
| 11. Legacy import | idempotens CSV import (kulcs: `Azonosító`), legacy ID megtartva, státusz-leképezéssel |
| 12. Kasszás nézet | sorszám/QR keresés → egy kattintásos beváltás, lejárat-ellenőrzés, audit |

## Adatmodell (egyedi táblák)

- `wp_pgv_vouchers` — egyedi utalványok (sorszám, státusz, összeg, megajándékozott, üzenet, kép, kézbesítés, QR, érvényesség, beváltás).
- `wp_pgv_audit` — minden státuszváltozás (törlés sehol).
- `wp_pgv_serial_counter` — hézagmentes per-egység + év számláló (atomikus, tranzakcióval).
- `wp_pgv_images` — elnevezett képkészlet egységenként.

A vevő/rendelés adatai a WooCommerce rendelésben (HPOS-kompatibilis) maradnak; a
személyre szabás a rendelés-tétel metában is eltárolódik.

## CRM olvasó API

```
GET /wp-json/pgv/v1/vouchers?updated_since=<ISO>&limit=100
GET /wp-json/pgv/v1/orders
GET /wp-json/pgv/v1/customers
```

Hitelesítés: `x-api-key: pk_…` fejléc (vagy `Authorization: Bearer pk_…`, illetve
`?api_key=pk_…` teszthez). A kulcs a **Beállítások** oldalon, egyszer jelenik meg
(csak a `sha256` hash-ét tároljuk). A válaszok a supabase `CRM_API.md` mezőit tükrözik,
lapozással (`next_cursor`) és inkrementális szinkronnal.

## Kiterjesztési pontok (hookok)

- `do_action( 'pgv_voucher_issued', $voucher_id, $voucher, $order )` — utalványonként, a PDF+QR generáláshoz / a meglévő HTML→PDF rendszerhez.
- `do_action( 'pgv_vouchers_issued_for_order', $order, $voucher_ids )` — rendelésenként, e-mailhez.
- `apply_filters( 'pgv_email_html', $html, $to, $subject )` — az e-mail HTML testreszabása.

## Fizetés / számlázás

- A **fizetést** a telepített WooCommerce fizetési átjáró adja (SimplePay: OTP SimplePay bővítmény ajánlott, SZÉP-kártyához). A plugin a **sikeres fizetés** eseményére köt.
- A **számlázást** (Számlázz.hu / Billingo) a hozzá tartozó WooCommerce bővítmény végzi, egységenkénti fiókkal. A plugin nem állít ki számlát.

## Ismert korlát / követő feladatok

- **Blokk-alapú checkout:** a marketing-checkbox és a szerveroldali cégnév-ellenőrzés a **klasszikus (shortcode) checkoutra** van kötve (`woocommerce_after_order_notes`, `woocommerce_checkout_process`). Blokk-checkoutnál ezekhez a `woocommerce_register_additional_checkout_field` API-val kell integrációt adni (követő feladat). A termékoldali személyre szabás és a kosár-megjelenítés blokktól függetlenül működik.
- A **PDF+QR** tényleges renderelése a `pgv_voucher_issued` hookra kötött rendszer feladata (a QR token adott).

## Nyitott döntések (HANDOVER 15. — könyvelő/üzlet)

Hézagmentesség jogi kötelezettsége; vásárláskori bizonylat típusa (nyugta/e-nyugta/semmi,
többcélú utalvány áfa); céges adat gyűjtése vs. csak figyelmeztetés; lejárt utalvány
kezelése; csoportszintű összesítő (Woo-nál a CRM a jó hely). Ezek a beállításokban
kapcsolókkal előkészítve (pl. `corporate_block`, `gapless_serial`).
