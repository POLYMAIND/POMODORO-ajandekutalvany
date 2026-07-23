-- ============================================================
-- Ajándékutalvány rendszer — alap séma (multi-tenant, per-unit)
-- Cél: Supabase / PostgreSQL 15+
-- Egy adatbázis, unitonként (Casa / Osteria / Pizzabar / Trattoria)
-- teljesen elkülönített adat RLS-sel. Szigorú számadás: hézagmentes
-- per-unit sorszám + hézagmentes audit napló, törlés sehol.
-- ============================================================

create extension if not exists pgcrypto;   -- gen_random_uuid()

-- ---------- Enumok ----------
create type order_status  as enum ('pending', 'paid', 'cancelled', 'expired');
create type voucher_status as enum ('pending', 'active', 'redeemed', 'cancelled', 'expired');
create type audit_action  as enum ('created', 'imported', 'paid', 'delivered', 'redeemed', 'cancelled', 'expired');
create type user_role     as enum ('group_admin', 'unit_admin', 'cashier');

-- ---------- unit: a bérlő / egység (külön cég, külön adószám) ----------
create table unit (
  id              uuid primary key default gen_random_uuid(),
  slug            text not null unique,              -- 'casa', 'osteria', 'pizzabar', 'trattoria'
  name            text not null,                     -- 'Casa Pomo d''Oro'
  serial_prefix   text not null,                     -- 'CASA', 'OST', 'PIZ', 'TRA'
  company_name    text,                              -- üzemeltető cég
  tax_number      text,                              -- adószám (szigorú számadás cégre szól)
  currency        text not null default 'HUF',
  validity_months int  not null default 12,          -- érvényesség vásárlástól (RESnWEB: 1 év)
  allowed_origin  text,                              -- iframe host (CORS / frame-ancestors)
  payment_config  jsonb not null default '{}'::jsonb,-- per-unit fizetési kulcsok (SimplePay/Stripe)
  active          bool not null default true,
  created_at      timestamptz not null default now()
);

-- ---------- app_user: admin / kasszás profil (auth.users kiterjesztése) ----------
-- Supabase: id = auth.users.id. Group_admin: unit_id = null (mind látja).
create table app_user (
  id         uuid primary key,                       -- = auth.uid()
  unit_id    uuid references unit(id) on delete restrict,
  role       user_role not null default 'cashier',
  name       text,
  created_at timestamptz not null default now()
);

-- ---------- voucher_image: utalványképek unitonként ----------
create table voucher_image (
  id           uuid primary key default gen_random_uuid(),
  unit_id      uuid not null references unit(id) on delete cascade,
  storage_path text not null,                        -- Supabase storage kulcs
  title        text,
  sort_order   int  not null default 0,
  active       bool not null default true,
  created_at   timestamptz not null default now()
);

-- ---------- voucher_denomination: katalógus (fix címletek) ----------
create table voucher_denomination (
  id         uuid primary key default gen_random_uuid(),
  unit_id    uuid not null references unit(id) on delete cascade,
  amount     int  not null check (amount > 0),       -- 30000
  label      text not null,                          -- '30 000 Ft értékű ajándékutalvány'
  description text,
  image_id   uuid references voucher_image(id) on delete set null,
  sort_order int  not null default 0,
  active     bool not null default true,
  created_at timestamptz not null default now()
);

-- ---------- serial_counter: hézagmentes per-unit sorszám (unit + év) ----------
create table serial_counter (
  unit_id    uuid not null references unit(id) on delete cascade,
  year       int  not null,
  last_value int  not null default 0,
  primary key (unit_id, year)
);

-- ---------- voucher_order: egy fizetés (rendelés), több utalványt tarthat ----------
create table voucher_order (
  id                uuid primary key default gen_random_uuid(),
  unit_id           uuid not null references unit(id) on delete restrict,
  order_ref         text not null unique,            -- publikus rendelésazonosító
  buyer_name        text,
  buyer_email       text,
  buyer_phone       text,
  buyer_country     text,
  buyer_zip         text,
  buyer_city        text,
  buyer_address     text,
  total_amount      int  not null default 0,
  status            order_status not null default 'pending',
  payment_provider  text,                            -- 'simplepay' / 'stripe'
  payment_ref       text,                            -- tranzakció / payment_intent
  paid_at           timestamptz,                     -- fizetés teljesülése
  corporate_flagged bool not null default false,     -- céges név/adószám észlelve
  raw               jsonb not null default '{}'::jsonb,
  created_at        timestamptz not null default now()
);

-- ---------- voucher: az egyedi ajándékutalvány (szigorú számadású egység) ----------
create table voucher (
  id             uuid primary key default gen_random_uuid(),
  unit_id        uuid not null references unit(id) on delete restrict,
  order_id       uuid references voucher_order(id) on delete restrict, -- import esetén null
  serial         text not null,                      -- új: 'CASA-2026-000042' | legacy: 'EIZQHA4AKRYF8'
  is_legacy      bool not null default false,        -- RESnWEB-ből importált
  amount         int  not null check (amount > 0),
  denomination_label text,                           -- pillanatkép a névről
  status         voucher_status not null default 'pending',
  giver_name     text,                               -- ajándékozó neve
  recipient_name text,                               -- megajándékozott neve
  message        text,                               -- üzenet a megajándékozottnak
  image_id       uuid references voucher_image(id) on delete set null,
  delivery_email text,
  pdf_path       text,                               -- generált PDF storage kulcs
  qr_token       text unique,                        -- QR-beváltás tokenje (új utalványok)
  valid_from     date,
  valid_until    date,
  redeemed_at    timestamptz,
  redeemed_by    uuid references app_user(id) on delete set null,
  created_at     timestamptz not null default now(),
  -- a sorszám unitonként egyedi (legacy és új is beleférjen)
  unique (unit_id, serial)
);

-- ---------- voucher_audit: szigorú számadású nyilvántartás ----------
create table voucher_audit (
  id          bigint generated always as identity primary key,
  unit_id     uuid not null references unit(id) on delete restrict,
  voucher_id  uuid not null references voucher(id) on delete restrict,
  action      audit_action not null,
  from_status voucher_status,
  to_status   voucher_status,
  actor       text not null default 'system',        -- auth.uid() szövegként, vagy 'webhook'/'import'
  detail      jsonb not null default '{}'::jsonb,
  occurred_at timestamptz not null default now()
);

-- ---------- Indexek ----------
create index idx_denom_unit      on voucher_denomination(unit_id) where active;
create index idx_image_unit      on voucher_image(unit_id) where active;
create index idx_order_unit      on voucher_order(unit_id);
create index idx_voucher_unit    on voucher(unit_id);
create index idx_voucher_status  on voucher(unit_id, status);
create index idx_voucher_serial  on voucher(unit_id, serial);
create index idx_voucher_qr      on voucher(qr_token);
create index idx_audit_voucher   on voucher_audit(voucher_id);
create index idx_audit_unit_time on voucher_audit(unit_id, occurred_at);

-- ============================================================
-- Sorszám-allokálás: atomikus, hézagmentes, per-unit + év
-- ============================================================
create or replace function allocate_serial(p_unit uuid)
returns text
language plpgsql
as $$
declare
  v_year   int := extract(year from now())::int;
  v_prefix text;
  v_next   int;
begin
  select serial_prefix into v_prefix from unit where id = p_unit;
  if v_prefix is null then
    raise exception 'Ismeretlen unit: %', p_unit;
  end if;

  -- A counter sorára szerzett zár garantálja a hézagmentességet
  -- párhuzamos fizetések esetén is.
  insert into serial_counter (unit_id, year, last_value)
       values (p_unit, v_year, 1)
  on conflict (unit_id, year)
       do update set last_value = serial_counter.last_value + 1
    returning last_value into v_next;

  return format('%s-%s-%s', v_prefix, v_year, lpad(v_next::text, 6, '0'));
end;
$$;

-- ============================================================
-- Audit-trigger: minden voucher státuszváltozást naplóz
-- ============================================================
create or replace function log_voucher_change()
returns trigger
language plpgsql
as $$
declare
  v_action audit_action;
begin
  if (tg_op = 'INSERT') then
    v_action := case when new.is_legacy then 'imported' else 'created' end;
    insert into voucher_audit(unit_id, voucher_id, action, from_status, to_status, actor)
    values (new.unit_id, new.id, v_action, null, new.status, coalesce(current_setting('app.actor', true), 'system'));
    return new;
  elsif (tg_op = 'UPDATE' and new.status is distinct from old.status) then
    v_action := case new.status
                  when 'active'    then 'paid'
                  when 'redeemed'  then 'redeemed'
                  when 'cancelled' then 'cancelled'
                  when 'expired'   then 'expired'
                  else 'created'
                end;
    insert into voucher_audit(unit_id, voucher_id, action, from_status, to_status, actor)
    values (new.unit_id, new.id, v_action, old.status, new.status, coalesce(current_setting('app.actor', true), 'system'));
    return new;
  end if;
  return new;
end;
$$;

create trigger trg_voucher_audit
after insert or update on voucher
for each row execute function log_voucher_change();

-- ============================================================
-- Fizetés jóváírása (webhookból hívandó, service_role)
-- Beállítja a rendelést fizetettre, minden utalványának sorszámot
-- ad, aktívra állítja és beállítja az érvényességet.
-- ============================================================
create or replace function mark_order_paid(p_order uuid, p_paid_at timestamptz, p_ref text)
returns void
language plpgsql
security definer
as $$
declare
  r_unit uuid;
  v_months int;
  v record;
begin
  select unit_id into r_unit from voucher_order where id = p_order for update;
  if r_unit is null then
    raise exception 'Ismeretlen rendelés: %', p_order;
  end if;
  select validity_months into v_months from unit where id = r_unit;

  update voucher_order
     set status = 'paid', paid_at = p_paid_at, payment_ref = p_ref
   where id = p_order and status = 'pending';

  perform set_config('app.actor', 'webhook', true);

  for v in select id from voucher where order_id = p_order and status = 'pending'
  loop
    update voucher
       set serial      = allocate_serial(r_unit),
           status      = 'active',
           valid_from  = (p_paid_at)::date,
           valid_until = (p_paid_at)::date + (v_months || ' months')::interval,
           qr_token    = encode(gen_random_bytes(16), 'hex')
     where id = v.id;
  end loop;
end;
$$;

-- ============================================================
-- Beváltás (kasszás felületről). Csak aktív utalványt vált be.
-- ============================================================
create or replace function redeem_voucher(p_voucher uuid)
returns voucher
language plpgsql
security definer
as $$
declare
  v voucher;
begin
  select * into v from voucher where id = p_voucher for update;
  if v.id is null then raise exception 'Nincs ilyen utalvány'; end if;
  if v.status <> 'active' then
    raise exception 'Nem beváltható (állapot: %)', v.status;
  end if;
  if v.valid_until is not null and v.valid_until < current_date then
    update voucher set status = 'expired' where id = p_voucher;
    raise exception 'Lejárt utalvány (érvényes volt: %)', v.valid_until;
  end if;

  perform set_config('app.actor', coalesce(auth.uid()::text, 'cashier'), true);
  update voucher
     set status = 'redeemed', redeemed_at = now(), redeemed_by = auth.uid()
   where id = p_voucher
   returning * into v;
  return v;
end;
$$;

-- ============================================================
-- Publikus katalógus a widgetnek (anon hívható, RLS-t megkerüli,
-- de csak a nyilvános mezőket adja vissza)
-- ============================================================
create or replace function public_catalog(p_slug text)
returns jsonb
language sql
security definer
stable
as $$
  select jsonb_build_object(
    'unit', jsonb_build_object('slug', u.slug, 'name', u.name, 'currency', u.currency),
    'denominations', coalesce((
      select jsonb_agg(jsonb_build_object(
               'id', d.id, 'amount', d.amount, 'label', d.label,
               'description', d.description, 'image', i.storage_path
             ) order by d.sort_order, d.amount)
      from voucher_denomination d
      left join voucher_image i on i.id = d.image_id
      where d.unit_id = u.id and d.active
    ), '[]'::jsonb)
  )
  from unit u
  where u.slug = p_slug and u.active;
$$;

-- ============================================================
-- RLS: per-unit izoláció
-- ============================================================
create or replace function current_user_unit()
returns uuid language sql stable as $$
  select unit_id from app_user where id = auth.uid();
$$;

create or replace function is_group_admin()
returns bool language sql stable as $$
  select exists (select 1 from app_user where id = auth.uid() and role = 'group_admin');
$$;

alter table unit                 enable row level security;
alter table app_user             enable row level security;
alter table voucher_image        enable row level security;
alter table voucher_denomination enable row level security;
alter table serial_counter       enable row level security;
alter table voucher_order        enable row level security;
alter table voucher              enable row level security;
alter table voucher_audit        enable row level security;

-- Alap minta: group_admin mindent lát, mindenki más csak a saját unitját.
create policy unit_read   on unit              for select using (is_group_admin() or id = current_user_unit());
create policy img_all     on voucher_image        using (is_group_admin() or unit_id = current_user_unit());
create policy denom_all   on voucher_denomination using (is_group_admin() or unit_id = current_user_unit());
create policy order_all   on voucher_order        using (is_group_admin() or unit_id = current_user_unit());
create policy voucher_all on voucher              using (is_group_admin() or unit_id = current_user_unit());
create policy audit_read  on voucher_audit     for select using (is_group_admin() or unit_id = current_user_unit());
create policy user_self   on app_user          for select using (is_group_admin() or id = auth.uid());

-- Írási jog a katalógusra csak admin szerepnek (kasszás nem ír).
create policy denom_write on voucher_denomination for all
  using (is_group_admin() or (unit_id = current_user_unit()
         and exists (select 1 from app_user where id = auth.uid() and role in ('group_admin','unit_admin'))));

-- serial_counter / audit írása csak SECURITY DEFINER függvényeken keresztül.

-- ============================================================
-- Seed: a négy egység (a címleteket / prefixeket igazítsátok)
-- ============================================================
insert into unit (slug, name, serial_prefix, currency, validity_months) values
  ('casa',      'Casa Pomo d''Oro',      'CASA', 'HUF', 12),
  ('osteria',   'Osteria Pomo d''Oro',   'OST',  'HUF', 12),
  ('pizzabar',  'Pizzabar Pomo d''Oro',  'PIZ',  'HUF', 12),
  ('trattoria', 'Trattoria Pomo d''Oro', 'TRA',  'HUF', 12);

-- Casa címletek (a screenshot alapján). A többi egységé kitöltendő.
insert into voucher_denomination (unit_id, amount, label, sort_order)
select u.id, x.amount, x.amount || ' Ft értékű ajándékutalvány', x.ord
from unit u,
     (values (30000,1),(35000,2),(40000,3),(50000,4)) as x(amount, ord)
where u.slug = 'casa';
