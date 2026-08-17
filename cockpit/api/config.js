const { getShops } = require('../lib/shops.js');
const { readSession } = require('../lib/auth.js');

module.exports = async (req, res) => {
  const s = readSession(req);
  const all = getShops().map(x => ({ slug: x.slug, name: x.name, prefix: x.prefix }));
  // Nem-superadmin csak a saját egységeit látja.
  const n = u => String(u == null ? '' : u).trim().toLowerCase();
  const mine = new Set((s ? (s.units || []) : []).map(n));
  const shops = (s && s.role !== 'superadmin')
    ? all.filter(x => mine.has(n(x.slug)))
    : all;

  res.status(200).json({
    authed: !!s,
    role: s ? s.role : null,
    name: s ? s.name : '',
    email: s ? s.email : '',
    units: s ? (s.units || []) : [],
    canManageUsers: !!s && s.role === 'superadmin',
    configured: shops.length > 0,
    shops,
  });
};
