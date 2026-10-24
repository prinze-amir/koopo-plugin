#!/usr/bin/env python3
"""Local-only login/REST regression smoke. Creates and removes one synthetic subscriber.
No credentials or token values are printed or written to the evidence report.
"""
import html, http.cookiejar, json, re, subprocess, urllib.error, urllib.parse, urllib.request
from pathlib import Path
BASE = 'http://localhost:8085'
results = []
def wp(code):
    p = subprocess.run(['docker','exec','wordpress_koopo','wp','eval',code,'--allow-root'],capture_output=True,text=True,check=True)
    return p.stdout.strip().splitlines()[-1] if p.stdout.strip() else ''
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl): return None

def client():
    jar=http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar),NoRedirect()),jar

def request(opener,path,data=None,json_body=False,headers=None):
    headers=headers or {}
    if data is not None:
        if json_body:
            body=json.dumps(data).encode();headers['Content-Type']='application/json'
        else:body=urllib.parse.urlencode(data).encode()
    else:body=None
    req=urllib.request.Request(BASE+path,data=body,headers=headers)
    try:resp=opener.open(req,timeout=30)
    except urllib.error.HTTPError as e:resp=e
    return resp.code,resp.headers,resp.read().decode(errors='replace')

def assert_case(name,condition):
    if not condition:raise AssertionError(name)
    results.append({'check':name,'passed':True});print('PASS '+name,flush=True)

def form(opener,dest=''):
    path='/login/'+('?redirect_to='+urllib.parse.quote(dest,safe='') if dest else '')
    status,headers,body=request(opener,path)
    m=re.search(r'name="_koopo_login_nonce" value="([^"]+)"',body)
    assert status==200 and m,'Login GET / nonce'
    return {'koopo_login_submit':'1','_koopo_login_nonce':m.group(1),'redirect_to':dest}

fixture=None
user=None
try:
    assert_case('Login implementation is owned by Koopo with no active standalone plugin', wp('require_once ABSPATH."wp-admin/includes/plugin.php";$source=(new ReflectionClass("Koopo_Login"))->getFileName();echo is_plugin_active("koopo/koopo.php")&&!is_plugin_active("koopo-login/koopo-login.php")&&strpos($source,"/koopo/includes/login/")!==false?"yes":"no";') == 'yes')
    # BuddyBoss reads privacy settings before WP_Rewrite exists in admin requests.
    admin_op, _ = client()
    status, headers, body = request(admin_op, '/wp-admin/')
    assert_case('Admin bootstrap reaches normal unauthenticated login redirect', status == 302 and '/wp-login.php' in headers.get('Location', '') and 'critical error' not in body.lower())
    status, headers, body = request(admin_op, '/wp-admin/admin-ajax.php')
    assert_case('Admin AJAX bootstrap reaches normal missing-action response', status == 400 and body.strip() == '0')
    # Only this throwaway account is mutated; example.invalid cannot receive mail.
    user=json.loads(wp('$name="koopo_login_smoke_".strtolower(wp_generate_password(8,false,false));$pw=wp_generate_password(32,true,true);$id=wp_insert_user(["user_login"=>$name,"user_pass"=>$pw,"user_email"=>$name."@example.invalid","display_name"=>"Login smoke fixture","role"=>"subscriber"]);if(is_wp_error($id))throw new Exception("Fixture creation failed");update_user_meta($id,"_koopo_date_of_birth","2000-01-01");update_user_meta($id,"_koopo_age_status","adult");echo wp_json_encode(["id"=>$id,"username"=>$name,"password"=>$pw]);'))
    op,jar=client()
    status,headers,body=request(op,'/login/')
    assert_case('Private Network guest can reach custom login',status==200 and 'kl-form' in body)
    assert_case('Login responses forbid caching','no-store' in headers.get('Cache-Control',''))
    assert_case('Native widget renders both enabled social providers','theChampFacebookLogin' in body and 'theChampGoogleLogin' in body)
    assert_case('Existing registration and recovery links retained','/register/' in body and '/my-account-koopo/lost-password/' in body)
    invalid={'koopo_login_submit':'1','_koopo_login_nonce':'expired','log':user['username'],'pwd':user['password']}
    status,headers,body=request(op,'/login/',invalid)
    assert_case('Expired/invalid nonce rejected without login',status==403 and 'expired' in body and not any(c.name.startswith('wordpress_logged_in') for c in jar))
    borrowed=form(op);borrowed.update(log=user['username'],pwd=user['password'])
    stranger,_=client()
    status,headers,body=request(stranger,'/login/',borrowed)
    assert_case('Guest nonce cannot be used without its browser cookie',status==403)
    status,headers,body=request(op,'/login/',borrowed,headers={'Origin':'https://untrusted.example'})
    assert_case('Foreign-origin login submission rejected',status==403)
    # Simulate an open/cached form whose browser cookie has disappeared.
    recovery,recovery_jar=client()
    status,headers,body=request(recovery,'/login/',borrowed)
    replacement=re.search(r'name="_koopo_login_nonce" value="([^"]+)"',body)
    assert_case('Rejected form restores missing guest cookie for the next attempt',status==403 and replacement is not None and any(c.name=='koopo_login_guest' for c in recovery_jar))
    retry=dict(borrowed);retry['_koopo_login_nonce']=replacement.group(1);retry['pwd']='not-the-password'
    status,headers,body=request(recovery,'/login/',retry)
    assert_case('Retry after missing-cookie error reaches password authentication',status==401 and 'expired' not in body)
    fresh,fresh_jar=client()
    status,headers,body=request(fresh,'/wp-admin/admin-ajax.php',{'action':'koopo_login_token'})
    token=json.loads(body)
    assert_case('Uncached token bootstrap creates a browser-bound guest token',status==200 and token.get('success') and token.get('data',{}).get('nonce') and 'no-store' in headers.get('Cache-Control','') and any(c.name=='koopo_login_guest' for c in fresh_jar))
    retry=dict(borrowed);retry['_koopo_login_nonce']=token['data']['nonce'];retry['pwd']='not-the-password'
    status,headers,body=request(fresh,'/login/',retry)
    assert_case('Refreshed token replaces stale form token and reaches authentication',status==401 and 'expired' not in body)
    payload=form(op);payload.update(log=user['username'],pwd='not-the-password')
    status,headers,body=request(op,'/login/',payload)
    assert_case('Wrong password stays on custom page without authentication',status==401 and 'kl-notice' in body and not headers.get('Location') and not any(c.name.startswith('wordpress_logged_in') for c in jar))
    wp(f'update_user_meta({user["id"]},"_koopo_owned_profile_login_mode","managed_only");echo "ready";')
    payload=form(op);payload.update(log=user['username'],pwd=user['password'])
    status,headers,body=request(op,'/login/',payload)
    assert_case('Managed-only profile cannot log in directly',status==401 and 'owner account' in body)
    wp(f'delete_user_meta({user["id"]},"_koopo_owned_profile_login_mode");update_user_meta({user["id"]},"_koopo_date_of_birth","2020-01-01");update_user_meta({user["id"]},"_koopo_age_status","suspended_underage");echo "ready";')
    payload=form(op);payload.update(log=user['username'],pwd=user['password'])
    status,headers,body=request(op,'/login/',payload)
    assert_case('Underage suspension policy remains enforced',status==401 and '18 or older' in body)
    wp(f'update_user_meta({user["id"]},"_koopo_date_of_birth","2000-01-01");update_user_meta({user["id"]},"_koopo_age_status","adult");echo "ready";')
    dest=BASE+'/koopo-contact/'
    payload=form(op,dest);payload.update(log=user['username'],pwd=user['password'],rememberme='forever')
    status,headers,body=request(op,'/login/',payload)
    assert_case('Successful login creates native cookie and honors configured BuddyBoss feed redirect',status==303 and headers.get('Location')==BASE+'/koopo-feed/' and any(c.name.startswith('wordpress_logged_in') for c in jar))
    assert_case('Remember-me cookie persists',any(c.name.startswith('wordpress_logged_in') and c.expires for c in jar))
    status,headers,body=request(op,'/login/')
    assert_case('Saved cookie recognized on next request','already signed in' in body)
    logout=re.search(r'href="([^"]*action=logout[^"]*)"',body)
    assert logout,'logout link'
    request(op,html.unescape(logout.group(1)).replace(BASE,''))
    assert_case('Native logout clears website session',not any(c.name.startswith('wordpress_logged_in') for c in jar))
    op,jar=client()
    payload=form(op,'https://untrusted.example/steal');payload.update(log=user['username'],pwd=user['password'])
    status,headers,body=request(op,'/login/',payload)
    assert_case('External return URL cannot cause an open redirect',status==303 and headers.get('Location','').startswith(BASE+'/'))
    op,jar=client()
    sso=BASE+'/koopo-ads-sso/?koopo_ads_sso=1&next=%2F'
    payload=form(op,sso);payload.update(log=user['username'],pwd=user['password'])
    status,headers,body=request(op,'/login/',payload)
    assert_case('Ads SSO return overrides normal BuddyBoss feed redirect',status==303 and headers.get('Location')==sso)
    encoded=wp('$target=home_url("/koopo-ads-sso/?next=%2F&koopo_ads_sso=1");parse_str(wp_parse_url(wp_login_url($target),PHP_URL_QUERY),$q);echo wp_json_encode(["preserved"=>$q["redirect_to"]===$target]);')
    assert_case('Login links preserve nested return URL query parameters',json.loads(encoded)['preserved'])
    op,jar=client()
    device='koopo-login-smoke-'+str(user['id'])
    status,headers,body=request(op,'/wp-json/koopo/v1/auth/login',{'username':user['username'],'password':user['password'],'device_id':device},True)
    session=json.loads(body)
    assert_case('Mobile REST password login still issues a session',status==200 and bool(session.get('access_token')) and bool(session.get('refresh_token')))
    status,headers,body=request(op,'/wp-json/koopo/v1/auth/refresh',{'refresh_token':session['refresh_token'],'device_id':device},True)
    refreshed=json.loads(body)
    assert_case('Mobile REST refresh still works',status==200 and bool(refreshed.get('access_token')))
    request(op,'/wp-json/koopo/v1/auth/logout',{'refresh_token':refreshed.get('refresh_token',session['refresh_token']),'device_id':device},True,{'Authorization':'Bearer '+refreshed['access_token']})
    # Simulate the SMS challenge contract without changing SMS settings or sending a message.
    fixture='/var/www/html/wp-content/mu-plugins/koopo-login-smoke-fixture.php'
    name=json.dumps(user['username'])
    php='<?php add_filter("authenticate",function($user,$name){if($name!=='+name+' || is_wp_error($user))return $user;if(($_POST["koopo_sms_code"]??"")!=="654321")return new WP_Error("koopo_sms_2fa_required","Test verification challenge");return $user;},999,2);add_action("login_form",function(){echo \'<label>SMS verification code<input name="koopo_sms_code" autocomplete="one-time-code"></label>\';});'
    subprocess.run(['docker','exec','-i','wordpress_koopo','sh','-c','cat > '+fixture],input=php,text=True,check=True)
    op,jar=client();payload=form(op);payload.update(log=user['username'],pwd=user['password'])
    status,headers,body=request(op,'/login/',payload)
    assert_case('SMS challenge remains on custom form without issuing cookie',status==200 and 'Test verification challenge' in body and 'name="koopo_sms_code"' in body and not any(c.name.startswith('wordpress_logged_in') for c in jar))
    payload=form(op);payload.update(log=user['username'],pwd=user['password'],koopo_sms_code='654321')
    status,headers,body=request(op,'/login/',payload)
    assert_case('SMS extension can complete native authentication',status==303 and any(c.name.startswith('wordpress_logged_in') for c in jar))
    subprocess.run(['docker','exec','wordpress_koopo','rm',fixture],check=True);fixture=None
    op,jar=client()
    for _ in range(10):
        payload=form(op);payload.update(log=user['username'],pwd='not-the-password');request(op,'/login/',payload)
    payload=form(op);payload.update(log=user['username'],pwd='not-the-password')
    status,headers,body=request(op,'/login/',payload)
    assert_case('Repeated failed attempts are rate limited',status==429 and headers.get('Retry-After')=='900')
finally:
    if fixture:subprocess.run(['docker','exec','wordpress_koopo','rm','-f',fixture],check=True)
    if user:
        # Fixture-only state cleanup and token revocation; preserve other users and data.
        wp(f'$id={user["id"]};$name={json.dumps(user["username"])};if(get_userdata($id)->user_login!==$name)throw new Exception("Fixture identity mismatch");require_once ABSPATH."wp-admin/includes/user.php";global $wpdb;$wpdb->delete(Koopo_Accounts_DB::table_auth_sessions(),["user_id"=>$id],["%d"]);wp_delete_user($id);delete_transient("koopo_login_identity_".hash_hmac("sha256",strtolower($name),wp_salt("auth")));echo "Fixture removed";')
Path('/Users/princeamir/Desktop/Plu2o/Anubus/output/login-elementor/http-smoke-results.json').write_text(json.dumps({'checks':results,'passed':len(results)},indent=2))
