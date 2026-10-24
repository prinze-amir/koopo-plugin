const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const php = fs.readFileSync(path.join(root, 'includes/creator-onboarding/class-koopo-creator-onboarding.php'), 'utf8');
const shortcodes = fs.readFileSync(path.join(root, 'includes/koopo-shortcodes.php'), 'utf8');
const js = fs.readFileSync(path.join(root, 'includes/creator-onboarding/assets/creator-onboarding.js'), 'utf8');

let checks = 0;
function assert(value, message) {
  checks += 1;
  if (!value) throw new Error(message);
}

assert(php.includes("'content_types'"), 'creator content types are persisted');
assert(php.includes("'primary_goal'"), 'a primary destination is persisted');
assert(php.includes("'affiliate_interest'"), 'affiliate interest is persisted');
assert(php.includes("$user->add_role( 'influencer' )"), 'creator role is granted after completion');
assert(php.includes("bp_core_activated_user"), 'new accounts wait for BuddyBoss activation');
assert(php.includes("'/new-seller/'"), 'product creators continue through seller onboarding');
assert(php.includes("Koopo_Video_Frontend::video_profile_url"), 'video creators land on their channel');
assert(php.includes("'/add-new-post/'"), 'article creators land in article creation');
assert(shortcodes.includes('creator_onboarding_required'), 'legacy direct role grant is gated');
assert(js.includes("X-WP-Nonce"), 'existing member completion is nonce protected');
assert(js.includes('ageConfirmationUrl'), 'existing member age confirmation uses the account policy');
assert(js.includes('prefers-reduced-motion'), 'motion respects user preference');

console.log(`PASS: ${checks} creator onboarding contract checks`);
