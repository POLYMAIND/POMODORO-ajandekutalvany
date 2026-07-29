-- ============================================================
-- ⚠️  CSAK TESZT / STAGING PROJEKTEN FUTTASD!
-- Ez fiktív rendeléseket és utalványokat szúr be. A rendszer szigorú
-- számadású (TÖRLÉS SEHOL), ezért ÉLES adatbázisba EZT NE töltsd —
-- utána nem lehet tisztán eltávolítani. Éles projekten csak a 001+002
-- migráció fusson.
-- ============================================================
-- Placeholder adatok teszthez (a prototípus mock adataiból)
-- A 001 már beszúrta a 4 egységet + a Casa címleteit.
-- Itt: a többi egység címletei, néhány kép, és rendelések + utalványok
-- vásárlói adatokkal, hogy a CRM API-nak legyen mit visszaadnia.
-- (Fiktív, example.com e-mailek.)
-- ============================================================

-- ---------- Címletek a többi egységhez ----------
insert into voucher_denomination (unit_id, amount, label, sort_order)
select (select id from unit where slug = x.slug), x.amt, x.amt || ' Ft értékű ajándékutalvány', x.ord
from (values
  ('osteria',30000,1),('osteria',40000,2),('osteria',50000,3),
  ('pizzabar',25000,1),('pizzabar',30000,2),('pizzabar',40000,3),
  ('trattoria',25000,1),('trattoria',30000,2),('trattoria',40000,3),('trattoria',50000,4),('trattoria',100000,5)
) as x(slug, amt, ord)
where not exists (
  select 1 from voucher_denomination d
  where d.unit_id = (select id from unit where slug = x.slug) and d.amount = x.amt
);

-- ---------- Néhány utalványkép ----------
insert into voucher_image (unit_id, storage_path, title, sort_order)
select (select id from unit where slug = x.slug), x.path, x.title, x.ord
from (values
  ('casa','casa/klasszikus.jpg','Klasszikus',1),
  ('casa','casa/unnepi.jpg','Ünnepi',2),
  ('osteria','osteria/rusztikus.jpg','Rusztikus',1),
  ('pizzabar','pizzabar/szines.jpg','Színes',1),
  ('trattoria','trattoria/elegans.jpg','Elegáns',1)
) as x(slug, path, title, ord)
where not exists (
  select 1 from voucher_image i
  where i.unit_id = (select id from unit where slug = x.slug) and i.title = x.title
);

-- ---------- Rendelések (egy fizetés = egy rendelés) ----------
insert into voucher_order (unit_id, order_ref, buyer_name, buyer_email, total_amount, status, payment_provider, paid_at, marketing_opt_in, created_at)
select (select id from unit where slug = x.slug), x.ref, x.name, x.email, x.amt, x.status::order_status,
       case when x.status = 'paid' then 'simplepay' end, x.paid, x.mkt, x.created
from (values
  ('casa','CASA-ORD-0001','Kovács Anna','kovacs.anna@example.com',50000,'paid',   timestamptz '2026-05-02 11:20', true,  timestamptz '2026-05-02 11:20'),
  ('casa','CASA-ORD-0002','Tóth Gábor','toth.gabor@example.com',35000,'paid',     timestamptz '2026-04-28 15:03', false, timestamptz '2026-04-28 15:03'),
  ('casa','CASA-ORD-0003','Szabó Réka','szabo.reka@example.com',30000,'paid',      timestamptz '2026-04-20 10:10', true,  timestamptz '2026-04-20 10:10'),
  ('casa','CASA-ORD-0004','Molnár Eszter','molnar.eszter@example.com',30000,'pending', null::timestamptz,          true,  timestamptz '2026-03-30 08:45'),
  ('osteria','OST-ORD-0001','Balogh Tímea','balogh.timea@example.com',40000,'paid', timestamptz '2026-06-01 12:00', true,  timestamptz '2026-06-01 12:00'),
  ('osteria','OST-ORD-0002','Nagy László','nagy.laszlo@example.com',30000,'paid',   timestamptz '2026-05-18 17:22', false, timestamptz '2026-05-18 17:22'),
  ('osteria','OST-ORD-0003','Horváth Judit','horvath.judit@example.com',50000,'paid',timestamptz '2025-11-11 10:00', false, timestamptz '2025-11-11 10:00'),
  ('pizzabar','PIZ-ORD-0001','Farkas Dóra','farkas.dora@example.com',25000,'paid',  timestamptz '2026-06-10 14:30', true,  timestamptz '2026-06-10 14:30'),
  ('pizzabar','PIZ-ORD-0002','Simon Bence','simon.bence@example.com',40000,'paid',  timestamptz '2026-05-25 11:11', false, timestamptz '2026-05-25 11:11'),
  ('trattoria','TRA-ORD-0001','Demeter Karolina','demeter.karolina@example.com',50000,'paid', timestamptz '2026-06-24 10:52', true,  timestamptz '2026-06-24 10:52'),
  ('trattoria','TRA-ORD-0002','Bolvári Krisztina','bolvari.krisztina@example.com',30000,'paid', timestamptz '2026-06-24 10:02', false, timestamptz '2026-06-24 10:02'),
  ('trattoria','TRA-ORD-0003','Bernáth András','bernath.andras@example.com',100000,'paid', timestamptz '2026-06-19 18:10', true, timestamptz '2026-06-19 18:10')
) as x(slug, ref, name, email, amt, status, paid, mkt, created)
on conflict (order_ref) do nothing;

-- ---------- Utalványok (a fizetett rendelésekhez) ----------
insert into voucher (unit_id, order_id, serial, amount, status, giver_name, recipient_name, delivery_email, valid_from, valid_until, created_at, redeemed_at)
select o.unit_id, o.id, v.serial, v.amount, v.status::voucher_status, o.buyer_name, v.recipient, o.buyer_email,
       o.paid_at::date, (o.paid_at::date + interval '12 months'), o.created_at, v.redeemed
from voucher_order o
join (values
  ('CASA-ORD-0001','CASA-2026-000042',50000,'active',  'Nagy Béla',       null::timestamptz),
  ('CASA-ORD-0002','CASA-2026-000041',35000,'redeemed','Édesanyám',       timestamptz '2026-06-01 19:30'),
  ('CASA-ORD-0003','CASA-2026-000040',30000,'active',  'Kis Péter',       null::timestamptz),
  ('OST-ORD-0001','OST-2026-000023',40000,'active',    'Balogh Ádám',     null::timestamptz),
  ('OST-ORD-0002','OST-2026-000022',30000,'active',    'Nagy Lászlóné',   null::timestamptz),
  ('OST-ORD-0003','OST-2026-000010',50000,'redeemed',  'Kolléga',         timestamptz '2026-01-20 18:00'),
  ('PIZ-ORD-0001','PIZ-2026-000017',25000,'active',    'Farkas Máté',     null::timestamptz),
  ('PIZ-ORD-0002','PIZ-2026-000016',40000,'active',    'Csapat',          null::timestamptz),
  ('TRA-ORD-0001','TRA-2026-000061',50000,'active',    null,              null::timestamptz),
  ('TRA-ORD-0002','TRA-2026-000060',30000,'active',    'Kovács László',   null::timestamptz),
  ('TRA-ORD-0003','TRA-2026-000048',100000,'redeemed', 'Marx Virág',      timestamptz '2026-07-10 20:05')
) as v(ref, serial, amount, status, recipient, redeemed) on v.ref = o.order_ref
on conflict (unit_id, serial) do nothing;
