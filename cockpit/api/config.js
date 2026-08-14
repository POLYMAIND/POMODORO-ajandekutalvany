const { getShops, isAuthed } = require('../lib/shops.js');

module.exports = async (req, res) => {
  const shops = getShops().map(s => ({ slug: s.slug, name: s.name, prefix: s.prefix }));
  res.status(200).json({
    authed: isAuthed(req),
    configured: shops.length > 0,
    shops,
    role: 'admin',
  });
};
