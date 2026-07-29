-- ============================================================
-- 002 — API-hozzáférés a CRM-nek + inkrementális szinkron
-- (a `voucher` sémában)
-- ============================================================

set search_path = voucher, public, extensions;

-- ---------- marketing hozzájárulás a rendelésen ----------
alter table voucher_order
  add column if not exists marketing_opt_in bool not null default false;

-- ---------- updated_at az inkrementális szinkronhoz ----------
alter table voucher_order add column if not exists updated_at timestamptz not null default now();
alter table voucher       add column if not exists updated_at timestamptz not null default now();

create or replace function touch_updated_at()
returns trigger language plpgsql
set search_path = voucher, public, extensions as $$
begin
  new.updated_at := now();
  return new;
end;
$$;

drop trigger if exists trg_order_touch on voucher_order;
create trigger trg_order_touch   before update on voucher_order for each row execute function touch_updated_at();

drop trigger if exists trg_voucher_touch on voucher;
create trigger trg_voucher_touch before update on voucher       for each row execute function touch_updated_at();

create index if not exists idx_order_updated   on voucher_order(updated_at);
create index if not exists idx_voucher_updated on voucher(updated_at);

-- ---------- API-kulcsok (a CRM olvasó hozzáféréséhez) ----------
-- A nyers kulcsot SOHA nem tároljuk; csak a sha256 hash-ét.
-- unit_id = null  -> a kulcs minden egységet lát (group scope).
-- unit_id kitöltve -> csak az adott egység adatait.
create table if not exists api_key (
  id           uuid primary key default gen_random_uuid(),
  unit_id      uuid references unit(id) on delete cascade,
  name         text not null,
  key_hash     text not null unique,                 -- encode(digest(raw,'sha256'),'hex')
  scopes       text[] not null default array['read'],
  active       bool   not null default true,
  last_used_at timestamptz,
  created_at   timestamptz not null default now()
);

alter table api_key enable row level security;
-- Nincs policy: csak a service_role (Edge Function) éri el, az RLS-t megkerüli.

-- ============================================================
-- Segéd: új API-kulcs létrehozása (SECURITY DEFINER).
-- Visszaadja az EGYSZER látható nyers kulcsot; utána csak a hash marad.
-- Hívás példa (SQL editor):
--   select * from create_api_key('CRM – teljes', null);
--   select * from create_api_key('CRM – csak Casa', (select id from unit where slug='casa'));
-- ============================================================
-- ============================================================
-- Jogosultságok a Supabase API-szerepeknek a voucher sémára.
-- (Egyedi sémán ez nem automatikus, mint a public-nál — kézzel kell.)
-- A service_role (Edge Function / szerveroldal) teljes hozzáférés;
-- az anon/authenticated csak USAGE (a táblákhoz RLS + policy szabályoz).
-- ============================================================
grant usage on schema voucher to anon, authenticated, service_role;
grant all privileges on all tables    in schema voucher to service_role;
grant all privileges on all sequences in schema voucher to service_role;
grant execute on all routines         in schema voucher to service_role;
alter default privileges in schema voucher grant all on tables      to service_role;
alter default privileges in schema voucher grant all on sequences   to service_role;
alter default privileges in schema voucher grant execute on routines to service_role;

create or replace function create_api_key(p_name text, p_unit uuid default null)
returns table(api_key_id uuid, raw_key text)
language plpgsql
security definer
set search_path = voucher, public, extensions
as $$
declare
  v_raw  text := 'pk_' || encode(gen_random_bytes(24), 'hex');
  v_hash text := encode(digest(v_raw, 'sha256'), 'hex');
  v_id   uuid;
begin
  insert into api_key(unit_id, name, key_hash)
       values (p_unit, p_name, v_hash)
    returning id into v_id;
  return query select v_id, v_raw;
end;
$$;
