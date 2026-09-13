const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const dir = process.argv[2];
for (const type of ['membership','site_plan']) {
  const d = new JSDOM(fs.readFileSync(`${dir}/${type}.html`, 'utf8')).window.document;
  assert(d.querySelector(`.kod--${type}`));
  assert(!d.querySelector('.kod-group').textContent.includes('Vendor:'));
  assert(!d.querySelector('.kod-group--product'));
  assert(d.querySelector('.kod-sidebar'));
  const links = [...d.querySelectorAll('.kod-group a')];
  assert(links.some(a => a.textContent.includes(type === 'membership' ? 'View channel' : 'Manage site plan')));
  if(type === 'membership') {
    assert(links.some(a => a.textContent.includes('Manage subscription')));
    assert(d.querySelector('.kod-group').textContent.includes('Next payment'));
  }
  console.log(`PASS ${type}: identity, native management and no vendor attribution`);
}
