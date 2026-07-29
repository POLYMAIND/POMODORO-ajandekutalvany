# Pomo d'Oro — CRM olvasó API

Csak-olvasó REST API a CRM-nek: vásárlók / rendelések / utalványok lekérdezése,
egység szerinti szűréssel és inkrementális szinkronnal. Supabase Edge Function
(`crm-api`), API-kulcsos hitelesítéssel. Az adat a `voucher` Postgres-sémából jön.

---

## Base URL
```
https://<PROJECT_REF>.supabase.co/functions/v1/crm-api
```
- Éles: `https://eikovjeybyrrxgjyggem.supabase.co/functions/v1/crm-api`
- (Teszt branch: a branch saját `<REF>.supabase.co` címén.)

## Hitelesítés
Minden kéréshez API-kulcs kell. Három mód (az elsőt ajánljuk):
```
x-api-key: pk_....                     # HTTP fejléc (ajánlott)
Authorization: Bearer pk_....          # fejléc, alternatíva
?api_key=pk_....                       # query paraméter (kényelmes teszthez;
                                       #  URL-ekben naplózódhat, élesben kerüld)
```
A kulcs **egységre korlátozható** — ekkor a válasz csak az adott egység adatait
tartalmazza, a `unit` paramétert felülírja. Kulcs létrehozása/visszavonása: lásd
lent, „Kulcskezelés".

## Közös query paraméterek
| Param | Leírás |
|-------|--------|
| `unit` | egység slug szűrő: `casa` \| `osteria` \| `pizzabar` \| `trattoria` (kulcs-scope felülírja) |
| `updated_since` | ISO időbélyeg — csak az azóta változott sorok (inkrementális szinkron) |
| `cursor` | lapozás: az előző válasz `next_cursor` értéke (a legutolsó `updated_at`) |
| `limit` | max sor (alap 100, max 500) |

Minden időbélyeg **ISO 8601, UTC** (`+00:00`). Az összegek **HUF-ban**, egész számként.
Az API **csak olvas** (GET); írás/törlés nincs.

---

## GET /orders
Rendelések (egy fizetés = egy rendelés). Lapozott.

```json
{
  "data": [
    {
      "order_ref": "CASA-ORD-0001",
      "unit": "casa",
      "buyer_name": "Kovács Anna",
      "buyer_email": "kovacs.anna@example.com",
      "amount": 50000,
      "status": "paid",
      "payment_provider": "simplepay",
      "marketing_opt_in": true,
      "paid_at": "2026-05-02T11:20:00+00:00",
      "created_at": "2026-05-02T11:20:00+00:00",
      "updated_at": "2026-07-29T12:32:58.937333+00:00"
    }
  ],
  "next_cursor": null
}
```
| Mező | Típus | Megjegyzés |
|------|-------|-----------|
| `order_ref` | string | publikus rendelésazonosító (stabil kulcs a CRM oldali upserthez) |
| `unit` | string | egység slug |
| `buyer_name`, `buyer_email` | string | vevő (számlázási) adatai |
| `amount` | int | rendelés összege (HUF) |
| `status` | enum | `pending` \| `paid` \| `cancelled` \| `expired` |
| `payment_provider` | string\|null | pl. `simplepay`; `pending`-nél `null` |
| `marketing_opt_in` | bool | hírlevél-hozzájárulás (GDPR — marketinghez ez az irányadó) |
| `paid_at` | ts\|null | fizetés ideje; `pending`-nél `null` |
| `created_at`, `updated_at` | ts | `updated_at` az inkrementális szinkron kulcsa |

## GET /vouchers
Egyedi utalványok (egy rendeléshez több is tartozhat). Lapozott.

```json
{
  "data": [
    {
      "serial": "CASA-2026-000042",
      "unit": "casa",
      "amount": 50000,
      "status": "active",
      "giver_name": "Kovács Anna",
      "recipient_name": "Nagy Béla",
      "delivery_email": "kovacs.anna@example.com",
      "valid_from": "2026-05-02",
      "valid_until": "2027-05-02",
      "redeemed_at": null,
      "is_legacy": false,
      "created_at": "2026-05-02T11:20:00+00:00",
      "updated_at": "2026-07-29T12:32:58.937333+00:00"
    }
  ],
  "next_cursor": null
}
```
| Mező | Típus | Megjegyzés |
|------|-------|-----------|
| `serial` | string | sorszám (új: `CASA-2026-000042`, legacy: pl. `EIZQHA4AKRYF8`) |
| `status` | enum | `pending` \| `active` \| `redeemed` \| `cancelled` \| `expired` |
| `recipient_name`, `giver_name` | string\|null | megajándékozott / ajándékozó |
| `valid_from`, `valid_until` | date | érvényesség |
| `redeemed_at` | ts\|null | beváltás ideje (ha beváltották) |
| `is_legacy` | bool | RESnWEB-ből importált-e |

## GET /customers
Vásárlók e-mail szerint aggregálva (szegmentáláshoz). **Nem lapozott** (aggregált nézet);
szűrhető `unit` / `updated_since` paraméterrel.

```json
{
  "data": [
    {
      "email": "kovacs.anna@example.com",
      "name": "Kovács Anna",
      "orders": 1,
      "total_spent": 50000,
      "units": ["casa"],
      "marketing_opt_in": true,
      "first_purchase": "2026-05-02T11:20:00+00:00",
      "last_purchase": "2026-05-02T11:20:00+00:00"
    }
  ]
}
```
| Mező | Típus | Megjegyzés |
|------|-------|-----------|
| `orders` | int | rendelések száma |
| `total_spent` | int | fizetett rendelések összege (HUF) |
| `units` | string[] | mely egységekben vásárolt (szegmentáláshoz) |
| `marketing_opt_in` | bool | igaz, ha bármely rendelésénél hozzájárult |

---

## Lapozás / inkrementális szinkron
1. Első teljes szinkron: hívd `/orders`-t (majd `/vouchers`-t) `limit`-tel, és amíg
   `next_cursor` nem `null`, add tovább: `?cursor=<next_cursor>`.
2. Tárold el a legnagyobb látott `updated_at`-et.
3. Következő szinkronnál: `?updated_since=<tárolt_updated_at>` → csak a változások jönnek.
4. A CRM oldalon **upsert** az `order_ref` (rendelés) ill. `serial` (utalvány) kulcsra
   — így idempotens, nem duplázódik.

## Hibaválaszok
| HTTP | Body | Ok |
|------|------|----|
| 401 | `{"error":"Hiányzó API kulcs"}` | nincs kulcs a kérésben |
| 401 | `{"error":"Érvénytelen API kulcs"}` | ismeretlen/letiltott kulcs |
| 400 | `{"error":"Ismeretlen egység: ..."}` | rossz `unit` slug |
| 404 | `{"error":"Ismeretlen végpont..."}` | rossz útvonal |
| 405 | `{"error":"Csak GET"}` | nem GET metódus |

## Kulcskezelés (Supabase SQL Editor)
```sql
-- Új kulcs (a raw_key CSAK egyszer látszik!):
select * from voucher.create_api_key('CRM – teljes', null);                              -- minden egység
select * from voucher.create_api_key('CRM – Casa', (select id from voucher.unit where slug='casa'));  -- egy egység

-- Kulcs letiltása:
update voucher.api_key set active = false where name = 'CRM – Casa';

-- Kulcsok listája (a nyers kulcs nincs tárolva, csak hash):
select id, name, unit_id, active, last_used_at, created_at from voucher.api_key;
```

## Biztonság / GDPR
- A nyers kulcsot nem tároljuk, csak `sha256` hash-ét; a kulcs bármikor letiltható,
  és egységre korlátozható.
- A `/customers` és a `buyer_email` személyes adat. Marketing-célú felhasználáshoz a
  `marketing_opt_in = true` az irányadó; tranzakciós/számviteli célra a teljes adat
  használható.
- HTTPS kötelező; a query-paraméteres kulcsot csak teszthez ajánljuk (fejléc élesben).
