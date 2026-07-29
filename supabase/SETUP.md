# Supabase beüzemelés — CRM API teszthez és éleshez

Ez az útmutató végigvezet: séma feltöltése, (teszt) adat, a CRM olvasó API
(Edge Function) telepítése, API-kulcs létrehozása és a hívás tesztelése.

> ⚠️ **Éles vs. teszt.** A rendszer **szigorú számadású** (törlés sehol).
> A `seed/placeholder_test_data.sql` fiktív rendeléseket szúr be — **ezt csak
> teszt/staging projekten futtasd**, éles adatbázisba SOHA. Éles projekten csak
> a `migrations/` (001 + 002) fusson.

A migrációkat helyi PostgreSQL 16-on teszteltük: 001 + 002 + seed hibátlanul
lefut, az audit napló, a marketing-flag, az `updated_at` trigger és az
API-kulcs + hash működik.

---

## 0. Előfeltételek
- Egy **Supabase projekt** (éleshez: dedikált projekt ehhez a rendszerhez).
- **Supabase CLI**: `npm i -g supabase` (vagy `brew install supabase/tap/supabase`).
- A projekt **ref**-je (Dashboard → Project Settings → General → Reference ID).

## 1. Projekt linkelése
```bash
supabase login
supabase link --project-ref <PROJECT_REF>
```

## 2. Séma feltöltése (éles + teszt egyaránt)
A `supabase/migrations/` tartalma (001 = séma + 4 egység + Casa címletek,
002 = API-kulcsok + inkrementális szinkron mezők):
```bash
supabase db push
```

## 3. Teszt-adat — CSAK teszt/staging projekten
```bash
# a projekt DB-connection stringjével (Dashboard → Database → Connection string)
psql "$SUPABASE_DB_URL" -f supabase/seed/placeholder_test_data.sql
```
Ez beszúrja a placeholder egységeket/címleteket/képeket + rendeléseket és
utalványokat (fiktív example.com vevőkkel), hogy a CRM API-nak legyen mit
visszaadnia.

## 4. CRM olvasó API (Edge Function) telepítése
A függvény API-kulccsal hitelesít (nem Supabase-JWT-vel), ezért **JWT-ellenőrzés
nélkül** kell telepíteni:
```bash
supabase functions deploy crm-api --no-verify-jwt
```
> A `SUPABASE_URL` és `SUPABASE_SERVICE_ROLE_KEY` változókat a Supabase
> automatikusan elérhetővé teszi az Edge Functionnek — nem kell kézzel beállítani.

## 5. API-kulcs létrehozása
Az SQL editorban (vagy psql-lel). A **nyers kulcs csak egyszer** látszik, utána
csak a hash marad:
```sql
-- teljes hozzáférés (mind a négy egység):
select * from create_api_key('CRM – teljes', null);

-- vagy csak egy egységre korlátozva:
select * from create_api_key('CRM – csak Casa', (select id from unit where slug = 'casa'));
```
Másold ki a `raw_key` értékét (pl. `pk_...`), ezt kapja a CRM.

## 6. Teszthívás
```bash
BASE="https://<PROJECT_REF>.supabase.co/functions/v1/crm-api"
KEY="pk_...."   # az 5. lépésből

# Rendelések (vevő + egység + összeg + státusz + dátumok + marketing)
curl -s "$BASE/orders?limit=50"           -H "x-api-key: $KEY" | jq

# Csak egy egység, adott dátum óta (inkrementális szinkron)
curl -s "$BASE/orders?unit=casa&updated_since=2026-01-01T00:00:00Z" -H "x-api-key: $KEY" | jq

# Utalványok (sorszám + beváltás)
curl -s "$BASE/vouchers?unit=trattoria"   -H "x-api-key: $KEY" | jq

# Vásárlók e-mail szerint aggregálva (szegmentáláshoz)
curl -s "$BASE/customers"                 -H "x-api-key: $KEY" | jq
```
`Authorization: Bearer $KEY` fejléc is működik az `x-api-key` helyett.

### Lapozás / inkrementális szinkron
A `orders` és `vouchers` válasz tartalmaz egy `next_cursor` mezőt (az utolsó
`updated_at`). A következő oldalt így kéred:
```bash
curl -s "$BASE/orders?cursor=<next_cursor>" -H "x-api-key: $KEY" | jq
```
A CRM így csak a változásokat szinkronizálja: eltárolja a legutóbbi
`updated_at`-et, és legközelebb `updated_since`/`cursor` paraméterrel kér.

---

## Végpontok összefoglaló (mind GET, csak olvasás)
| Útvonal | Mit ad vissza |
|--------|----------------|
| `/crm-api/orders` | rendelések: `order_ref, unit, buyer_name, buyer_email, amount, status, marketing_opt_in, paid_at, created_at, updated_at` |
| `/crm-api/vouchers` | utalványok: `serial, unit, amount, status, recipient_name, giver_name, valid_from, valid_until, redeemed_at, is_legacy, updated_at` |
| `/crm-api/customers` | vevők e-mail szerint aggregálva: `email, name, orders, total_spent, units[], marketing_opt_in, first/last_purchase` |

Közös paraméterek: `unit=<slug>`, `updated_since=<ISO>`, `cursor=<ISO>`, `limit` (max 500).
Az egység-scope-olt kulcs felülírja a `unit` paramétert (csak a saját egységét látja).

## Biztonság
- A nyers API-kulcsot **nem** tároljuk, csak `sha256` hash-ét (`api_key.key_hash`).
- A kulcs **letiltható** (`update api_key set active=false where id=...`) vagy
  egységre korlátozható.
- Az `api_key` táblát csak a `service_role` (az Edge Function) éri el (RLS, policy nélkül).
- A `customers` végpont személyes adatot ad vissza — a CRM oldalon a
  marketing-célú felhasználáshoz a `marketing_opt_in=true` szűrő az irányadó (GDPR).
