const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('node:assert/strict');
const root=process.argv[2];
for(const type of ['product','support','booking','ticket']){
 const html=fs.readFileSync(`${root}/${type}.html`,'utf8'),d=new JSDOM(html).window.document;
 assert(!/Fatal error|Warning:/.test(html));assert(d.querySelector(`.kod--${type}`));assert.equal(d.querySelectorAll('.kod-totals').length,1);
 assert(d.querySelector('.kod-totals .kod-total'));assert(d.querySelector(`.kod-group--${type}`));
 for(const row of d.querySelectorAll('.kod-items tbody tr.order_item'))assert.equal(row.children.length,2);
 assert.equal(d.querySelectorAll('.koopo-booking-card').length,0);
 if(type==='ticket')assert(d.querySelector('.koopo-order-tickets a[href*="koopo_ticket_print"]'));
 if(type==='booking')assert([...d.querySelectorAll('.kod-button')].some(a=>a.textContent.includes('Manage booking')));
 console.log(`PASS ${type}: scoped layout, totals, native two-column items and relevant actions`);
}
const denied=new JSDOM(fs.readFileSync(`${root}/denied.html`,'utf8')).window.document;
assert.equal(denied.querySelector('.kod'),null);assert(denied.body.textContent.includes('Invalid order'));
console.log('PASS unauthorized view contains no order details');
