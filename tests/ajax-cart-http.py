"""Local endpoint acceptance. Pass the JSON output of ajax-cart-fixtures.php as a file."""
import http.cookiejar
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ids = json.load(open(sys.argv[1]))
base = 'http://localhost:8085/'
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def post(kind, fields, headers=None):
    data = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(base + '?wc-ajax=koopo_' + kind, data=data,
                                 headers=headers if headers is not None else {'X-Koopo-Cart': '1'})
    started = time.monotonic()
    try:
        response = client.open(req)
    except urllib.error.HTTPError as error:
        response = error
    result = json.load(response)
    print(kind, response.status, round(time.monotonic() - started, 2), 'seconds')
    return result

def check(value, name):
    assert value, name
    print('PASS', name)

def add(product, **fields):
    return post('cart_add', {'koopo_product_id': ids[product], **fields})

check(not post('cart_add', {'koopo_product_id': ids['simple']}, {})['success'], 'header required')
check(not post('cart_add', {'koopo_product_id': ids['simple']}, {'X-Koopo-Cart':'1', 'Origin':'https://other.example'})['success'], 'cross-origin rejected')
check(not add('simple', **{'add-to-cart':ids['simple']})['success'], 'native double-submit input rejected')
r = add('simple', quantity=2)['data']
check(r['added'] and r['added_ids'] == [ids['simple']] and r['fragments'], 'simple add and fragments')
check(not add('simple', quantity=-1)['success'], 'negative quantity rejected')
r = add('variable', quantity=1)['data']
check(not r['added'] and not r['added_ids'], 'missing variation rejected')
r = add('variable', quantity=1, variation_id=ids['variation'], attribute_size='Large')['data']
check(r['added'] and 'Large' in str(r['fragments']), 'wildcard selected attribute retained')
r = add('variable', quantity=1, variation_id=ids['variation'], attribute_size='Invalid')['data']
check(not r['added'], 'invalid attribute rejected')
r = add('grouped', **{f'quantity[{ids["simple"]}]':1, f'quantity[{ids["limited"]}]':2})['data']
check(not r['added'] and r['added_ids'] == [ids['simple']], 'group partial success reports only successful child')
r = add('grouped', **{f'quantity[{ids["simple"]}]':0})['data']
check(not r['added'], 'empty group rejected')
r = add('grouped', **{f'quantity[{ids["simple"]}]':1, f'quantity[{ids["limited"]}]':''})['data']
check(r['added'] and r['added_ids'] == [ids['simple']], 'blank unselected grouped quantity allowed')
check(not add('grouped', **{f'quantity[{ids["page"]}]':1})['success'], 'foreign grouped child rejected')
for product in ['variable', 'grouped']:
    r = post('product_view', {'koopo_product_id':ids[product]})
    check(r['success'] and 'koopo_product_id' in r['data']['html'], product + ' modal form rendered')
print('All local endpoint checks passed.')
