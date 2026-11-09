# Új ügyfél kasszás felülete (white-label telepítés)

A kassza (beváltás, beváltott lista, lejáratok, export, felhasználók) ügyfelenként külön fut: saját
Vercel-projekt, saját Neon-adatbázis, saját domain. A kód közös — ez a mappa, ugyanerről az ágról.

## 1. Vercel-projekt
1. Vercel → Add New → Project → `POLYMAIND/POMODORO-ajandekutalvany`.
2. **Root Directory:** `cockpit` · **Production Branch:** ugyanaz, mint a Pomo d'Oro projektnél.
3. Név: `ajandekutalvany-<ugyfel>` (pl. `ajandekutalvany-zenobia`).

## 2. Adatbázis
Vercel → a projekt → Storage → Create → **Neon Postgres** → Connect. A `DATABASE_URL`/`POSTGRES_URL`
magától bekerül; a táblákat az első kérés hozza létre.

## 3. Környezeti változók
| Változó | Érték |
|---|---|
| `BRAND_NAME` | az ügyfél neve, pl. `Zenobia` |
| `BRAND_LOGO_URL`, `BRAND_COLOR` | nem kötelező |
| `APP_PASSWORD` | erős mesterjelszó (a központi admin belépéséhez) |
| `AUTH_SECRET` | hosszú véletlen szöveg (`openssl rand -hex 32`) |
| `SHOPS` | `[{"slug":"<egyseg-azonosito>","name":"<Egység neve>","prefix":"<ELŐTAG>"}]` — az azonosító ugyanaz, mint a POLYMAIND-vezérlőpultban |
| `VOUCHER_LICENCE_SHA256` | a licenc hash-e: `printf %s 'vfl_…' \| sha256sum` |
| `EXPORT_PREFIX` | pl. `zenobia` |
| `BREVO_API_KEY` | ha a kassza küld e-mailt (újraküldés, lejárat-emlékeztető) — vagy a felületen állítható |

## 4. Domain
Vercel → Domains → `<ugyfel>.ajandekutalvany.polyapp.hu`; DNS: `CNAME <ugyfel>.ajandekutalvany → cname.vercel-dns.com`.

## 5. Összekötés
A POLYMAIND-vezérlőpultban (`ajandekutalvany.polyapp.hu/admin`) az ügyfél **Kassza címe** mezőjébe
írd be a fenti domaint (`https://…`). A bővítmény a következő szinkronnál átveszi, és a licencével
küldi fel ide az utalványokat — külön titkot nem kell beírni.

## 6. Felhasználók
Belépés a mesterjelszóval (e-mail üresen) → Felhasználók: egység-kezelők és kasszások felvétele.
