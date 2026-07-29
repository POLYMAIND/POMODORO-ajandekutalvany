// ============================================================
// CRM olvasó API — Supabase Edge Function
// API-kulcsos hitelesítés (sha256 hash), per-egység scope, inkrementális
// lapozás (updated_since / cursor). CSAK olvasás.
//
// Végpontok (mind GET):
//   /crm-api/orders     — rendelések (vevő + egység + összeg + státusz + dátumok)
//   /crm-api/vouchers   — utalványok (sorszám + státusz + beváltás)
//   /crm-api/customers  — vásárlók e-mail szerint aggregálva (szegmentáláshoz)
//
// Közös query paraméterek:
//   unit=casa|osteria|pizzabar|trattoria   (a kulcs egység-scope-ja felülírja)
//   updated_since=2026-01-01T00:00:00Z      (inkrementális szinkron)
//   cursor=<updated_at ISO>                 (lapozás; a válasz next_cursor-jából)
//   limit=100                               (max 500)
//
// Auth:  Authorization: Bearer <kulcs>   VAGY   x-api-key: <kulcs>
// ============================================================

import { createClient } from "https://esm.sh/@supabase/supabase-js@2";

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const SERVICE_ROLE = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;

const CORS = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Methods": "GET, OPTIONS",
  "Access-Control-Allow-Headers": "authorization, x-api-key, content-type",
};

function json(status: number, body: unknown) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { ...CORS, "content-type": "application/json; charset=utf-8" },
  });
}

async function sha256hex(s: string): Promise<string> {
  const buf = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(s));
  return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, "0")).join("");
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: CORS });
  if (req.method !== "GET") return json(405, { error: "Csak GET" });

  const admin = createClient(SUPABASE_URL, SERVICE_ROLE, { auth: { persistSession: false } });

  // --- API kulcs ---
  const raw = (req.headers.get("authorization")?.replace(/^Bearer\s+/i, "") ||
    req.headers.get("x-api-key") || "").trim();
  if (!raw) return json(401, { error: "Hiányzó API kulcs" });

  const hash = await sha256hex(raw);
  const { data: key } = await admin
    .from("api_key").select("id, unit_id, scopes, active")
    .eq("key_hash", hash).eq("active", true).maybeSingle();
  if (!key) return json(401, { error: "Érvénytelen API kulcs" });
  // last_used_at frissítése (tűz-és-felejtsd)
  admin.from("api_key").update({ last_used_at: new Date().toISOString() }).eq("id", key.id).then(() => {});

  const url = new URL(req.url);
  const resource = url.pathname.split("/").filter(Boolean).pop();
  const sinceParam = url.searchParams.get("updated_since");
  const cursor = url.searchParams.get("cursor");
  const since = cursor || sinceParam;
  const limit = Math.min(parseInt(url.searchParams.get("limit") || "100", 10) || 100, 500);

  // --- Egység feloldása (scope) ---
  let unitId: string | null = key.unit_id ?? null;
  if (!unitId) {
    const slug = url.searchParams.get("unit");
    if (slug) {
      const { data: u } = await admin.from("unit").select("id").eq("slug", slug).maybeSingle();
      if (!u) return json(400, { error: "Ismeretlen egység: " + slug });
      unitId = u.id;
    }
  }

  // egység slug-ok kikereséséhez (válaszban a slug-ot adjuk vissza, ne a UUID-t)
  const { data: units } = await admin.from("unit").select("id, slug, name");
  const slugById: Record<string, string> = {};
  const nameById: Record<string, string> = {};
  (units || []).forEach((u: any) => { slugById[u.id] = u.slug; nameById[u.id] = u.name; });

  try {
    if (resource === "orders" || resource === "customers") {
      let q = admin.from("voucher_order")
        .select("order_ref, unit_id, buyer_name, buyer_email, total_amount, status, payment_provider, marketing_opt_in, paid_at, created_at, updated_at")
        .order("updated_at", { ascending: true }).limit(resource === "orders" ? limit : 2000);
      if (unitId) q = q.eq("unit_id", unitId);
      if (since) q = q.gt("updated_at", since);
      const { data, error } = await q;
      if (error) throw error;

      if (resource === "orders") {
        const rows = (data || []).map((o: any) => ({
          order_ref: o.order_ref, unit: slugById[o.unit_id], buyer_name: o.buyer_name,
          buyer_email: o.buyer_email, amount: o.total_amount, status: o.status,
          payment_provider: o.payment_provider, marketing_opt_in: o.marketing_opt_in,
          paid_at: o.paid_at, created_at: o.created_at, updated_at: o.updated_at,
        }));
        const next = rows.length === limit ? rows[rows.length - 1].updated_at : null;
        return json(200, { data: rows, next_cursor: next });
      }

      // customers: e-mail szerint aggregálva (szegmentáláshoz)
      const map: Record<string, any> = {};
      (data || []).forEach((o: any) => {
        const email = (o.buyer_email || "").toLowerCase();
        if (!email) return;
        const c = map[email] || (map[email] = {
          email, name: o.buyer_name, orders: 0, total_spent: 0,
          units: new Set<string>(), marketing_opt_in: false,
          first_purchase: o.created_at, last_purchase: o.created_at,
        });
        c.orders += 1;
        if (o.status === "paid") c.total_spent += o.total_amount || 0;
        c.units.add(slugById[o.unit_id]);
        c.marketing_opt_in = c.marketing_opt_in || !!o.marketing_opt_in;
        if (o.created_at < c.first_purchase) c.first_purchase = o.created_at;
        if (o.created_at > c.last_purchase) c.last_purchase = o.created_at;
      });
      const customers = Object.values(map).map((c: any) => ({ ...c, units: [...c.units] }));
      return json(200, { data: customers, note: "Aggregált nézet (nem lapozott); szűrhető unit / updated_since paraméterrel." });
    }

    if (resource === "vouchers") {
      let q = admin.from("voucher")
        .select("serial, unit_id, order_id, amount, status, giver_name, recipient_name, delivery_email, valid_from, valid_until, redeemed_at, created_at, updated_at, is_legacy")
        .order("updated_at", { ascending: true }).limit(limit);
      if (unitId) q = q.eq("unit_id", unitId);
      if (since) q = q.gt("updated_at", since);
      const { data, error } = await q;
      if (error) throw error;
      const rows = (data || []).map((v: any) => ({
        serial: v.serial, unit: slugById[v.unit_id], amount: v.amount, status: v.status,
        giver_name: v.giver_name, recipient_name: v.recipient_name, delivery_email: v.delivery_email,
        valid_from: v.valid_from, valid_until: v.valid_until, redeemed_at: v.redeemed_at,
        is_legacy: v.is_legacy, created_at: v.created_at, updated_at: v.updated_at,
      }));
      const next = rows.length === limit ? rows[rows.length - 1].updated_at : null;
      return json(200, { data: rows, next_cursor: next });
    }

    return json(404, { error: "Ismeretlen végpont. Használható: /orders, /vouchers, /customers" });
  } catch (e) {
    return json(500, { error: String(e?.message || e) });
  }
});
