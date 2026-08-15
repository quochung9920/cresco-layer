import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/exact-runtime-export.js', import.meta.url), 'utf8');
const assets = fs.readFileSync(new URL('../../includes/Support/Assets.php', import.meta.url), 'utf8');

assert.match(source, /runtimeCapabilities/);
assert.match(source, /cresco-runtime-capabilities\/v1/);
assert.match(source, /capabilityLock/);
assert.match(source, /runtime-exact/);
assert.match(source, /inventControls:\s*false/);
assert.match(source, /inventResponsiveSuffixes:\s*false/);
assert.match(source, /only-when-no-native-control-can-express-property/);
assert.match(source, /\/elementor-catalog\//);
assert.match(source, /detailLoaded !== true/);
assert.match(source, /manifest\.contextProfile = 'exact-runtime'/);
assert.match(source, /siteDesignContext/);
assert.match(source, /Exact Runtime/);
assert.match(source, /value="exact"/);
assert.match(source, /constructionWidgets/);
assert.match(source, /'form'/);
assert.match(source, /constructionElements/);
assert.match(source, /'container'/);
assert.match(source, /window\.fetch = function/);
assert.match(source, /state\.profile !== 'exact'/);
assert.match(source, /function failed/);

assert.match(assets, /cresco-layer-exact-runtime-export/);
assert.match(assets, /assets\/exact-runtime-export\.js/);
assert.match(assets, /\[ 'cresco-layer-exact-runtime-export' \]/);

console.log('PASS: exact runtime AI export contract');
