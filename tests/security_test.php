<?php
/**
 * Regression tests for the findings of the security review of 2026-10-01.
 * Each block is one finding: what an attacker could do then, and that it is refused now.
 */
require __DIR__ . '/stubs.php';

use Gss\Plugin\System\GssAiImage\Helper\Bootstrap;
use Gss\Plugin\System\GssAiImage\Provider\{GeminiProvider, OpenAiProvider};
use Gss\Plugin\System\GssAiImage\Service\{AiImageException, DraftStore, HttpClient, ImageProcessor, Ratio, Settings, SkillStore, UploadedReferences, UsageLedger};
use Gss\Plugin\System\GssAiImageFlexi\Service\FlexiFieldWriter;
use Joomla\CMS\Http\HttpFactory;
use Joomla\Registry\Registry;

$root = sys_get_temp_dir() . '/gssai-sec-' . bin2hex(random_bytes(4)); mkdir($root, 0755, true);
define('JPATH_ROOT', $root);
$settings = new Settings(new Registry(['openai_api_key' => 'k', 'gemini_api_key' => 'g']));
$img = imagecreatetruecolor(1200, 675); imagefill($img, 0, 0, imagecolorallocate($img, 26, 91, 255)); ob_start(); imagewebp($img, null, 80); $webp = ob_get_clean();
$month = $root . '/images/ai-generated/' . date('Y/m');

echo "1. One draft can be applied once only\n";
$alice = new DraftStore($settings, 42, $root); $aliceSession = new FakeSession(); $aliceLedger = new UsageLedger(new FakeDb(), 42, $aliceSession);
$t = $alice->save($webp, 'webp'); $other = $alice->save($webp, 'webp');
$applyOnce = function () use ($alice, $aliceLedger, $t, $other) {
    $src = $alice->path($t);
    $aliceLedger->count('apply', 30);
    $path = $alice->publish(file_get_contents($src), 'webp', 'flood');
    Bootstrap::settle($alice, $aliceLedger, $t, $path, '', [$t, $other]);   // the attacker names the applied draft in keep
};
$applyOnce();
check('first apply works, and its draft is gone though it was named in keep', count(glob($month . '/flood-*.webp')) === 1 && refused(fn () => $alice->path($t)) === 'draft');
check('second apply of the same draft is refused', refused($applyOnce) === 'draft' && count(glob($month . '/flood-*.webp')) === 1);
check('a draft of another panel named in keep is kept', is_file($alice->path($other)));

echo "2. The hourly limit is the user's, not the session's\n";
$session = new FakeSession(); $ledger = new UsageLedger(new FakeDb(), 42, $session);
for ($i = 0; $i < 3; $i++) { $ledger->count('generate', 3); }
check('fourth generation in the hour refused', refused(fn () => $ledger->count('generate', 3)) === 'rate_limit');
check('apply has a count of its own', refused(fn () => $ledger->count('apply', 3)) === '');
check('limit 0 means no limit', refused(fn () => $ledger->count('generate', 0)) === '');
$session->d['plg_system_gssaiimage.usage']['generate'] = [time() - 3700, time() - 3601, time() - 10];
check('what is older than an hour no longer counts', refused(fn () => $ledger->count('generate', 3)) === '' && count($session->d['plg_system_gssaiimage.usage']['generate']) === 2);
$ledger->start(200);
check('a second image while one is being generated is refused', refused(fn () => $ledger->start(200)) === 'busy');
$ledger->finish();
check('free again when it finished', refused(fn () => $ledger->start(200)) === '');
$session->d['plg_system_gssaiimage.usage']['running'] = time() - 1;
check('a mark left by a crashed request expires', refused(fn () => $ledger->start(200)) === '');
echo "   (that the count survives signing in again is tested against a real database in joomla_ledger.php)\n";

echo "3. Only files the editor applied can be replaced\n";
$bob = new DraftStore($settings, 7, $root); $bobLedger = new UsageLedger(new FakeDb(), 7, new FakeSession());
$alicePath = $alice->publish($webp, 'webp', 'Alice article'); $aliceLedger->remember($alicePath);
$bt = $bob->save($webp, 'webp'); $bobPath = $bob->publish($webp, 'webp', 'Bob');
Bootstrap::settle($bob, $bobLedger, $bt, $bobPath, $alicePath, []);
check("Bob naming Alice's file as the one to replace leaves it alone", is_file($root . '/' . $alicePath));
$bt2 = $bob->save($webp, 'webp'); $bobPath2 = $bob->publish($webp, 'webp', 'Bob again');
Bootstrap::settle($bob, $bobLedger, $bt2, $bobPath2, $bobPath, []);
check('Bob replacing his own earlier file removes it', !is_file($root . '/' . $bobPath) && is_file($root . '/' . $bobPath2));
check('and it is forgotten, the new one remembered', !$bobLedger->owns($bobPath) && $bobLedger->owns($bobPath2) && !$bobLedger->owns($alicePath));
$bt3 = $bob->save($webp, 'webp');
Bootstrap::settle($bob, $bobLedger, $bt3, $bobPath2, $bobPath2, []);
check('a file is never replaced by itself', is_file($root . '/' . $bobPath2));

echo "4. Reference images that unpack to a huge bitmap\n";
$tmp = $root . '/tmp'; mkdir($tmp);
$make = function (int $w, int $h, string $name) use ($tmp) { $i = imagecreatetruecolor($w, $h); imagepng($i, $tmp . '/' . $name, 9); return ['name' => $name, 'tmp_name' => $tmp . '/' . $name, 'error' => 0, 'size' => filesize($tmp . '/' . $name)]; };
$up = new UploadedReferences($tmp, fn ($p) => is_file($p));
$bomb = $make(5000, 3000, 'bomb.png');
check('a 15 megapixel image of ' . round($bomb['size'] / 1024) . ' KB is refused before it is decoded', refused(fn () => $up->collect([$bomb])) === 'ref_size');
$fine = $make(2400, 1600, 'fine.png');
$paths = (new UploadedReferences($tmp, fn ($p) => is_file($p)))->collect([$fine]);
check('an ordinary image still goes through', count($paths) === 1 && getimagesize($paths[0])[0] === 1536);
check('files over 6 MB refused', refused(fn () => $up->collect([['name' => 'x.png', 'tmp_name' => $fine['tmp_name'], 'error' => 0, 'size' => 7 * 1048576]])) === 'ref_size');
check('memory check: 12 megapixels do not fit in 64 MB, 2 megapixels do', (function () { $old = ini_get('memory_limit'); ini_set('memory_limit', '64M'); $r = !ImageProcessor::fitsInMemory(4000, 3000) && ImageProcessor::fitsInMemory(1600, 1200); ini_set('memory_limit', $old); return $r; })());
$stale = $tmp . '/gssai-ref-' . str_repeat('ab', 12) . '.jpg'; file_put_contents($stale, 'x'); touch($stale, time() - 4000);
$fresh = $tmp . '/gssai-ref-' . str_repeat('cd', 12) . '.jpg'; file_put_contents($fresh, 'x');
$sweeper = new UploadedReferences($tmp, fn ($p) => is_file($p)); $sweeper->collect([$fine]); $sweeper->cleanup();
check('files a cut-short request left behind are swept after an hour, newer ones stay', !is_file($stale) && is_file($fresh) && is_file($tmp . '/fine.png'));

echo "5. FLEXIcontent: only its own kind of temporary item id\n";
$valid = date('_Y_m_d_h_i_s_') . uniqid(true);
check('the id FLEXIcontent makes is accepted: ' . $valid, FlexiFieldWriter::isTemporaryItemId($valid));
foreach (['345.', '345_field_17', 'tmp_abc123', '../345', "345\0", '_2026_13_40_01_01_01_16abe4d9f6c3b8', date('_Y_m_d_h_i_s_', time() - 10 * 86400) . uniqid(true), $valid . '/x', ''] as $bad) {
    check('made-up id refused: ' . json_encode($bad), !FlexiFieldWriter::isTemporaryItemId($bad));
}
$fdb = new FakeDb(); $fdb->rows['object'] = (object) ['id' => 17, 'name' => 'hero_image', 'label' => 'x', 'field_type' => 'image', 'attribs' => json_encode(['image_source' => 1, 'dir' => 'images/stories/flexicontent'])];
$fdb->rows['result'] = 42; $user = new \Joomla\CMS\User\User(); $ft = $alice->save($webp, 'webp');
$writer = new FlexiFieldWriter($fdb, $settings, $alice, $user, 'jpg', $root);
check('folder mode refuses a made-up id', refused(fn () => $writer->apply($ft, '17', '345_field_17', 't')) === 'item' && !is_dir($root . '/images/stories/flexicontent'));
$w = $writer->apply($ft, '17', '0345', 't');
check('a number with leading zeros lands in the real item folder', is_file($root . '/images/stories/flexicontent/item_345_field_17/original/' . $w['filename']));
$w = $writer->apply($ft, '17', $valid, 't');
check('a new item with a real temporary id works', is_file($root . '/images/stories/flexicontent/item_' . $valid . '_field_17/original/' . $w['filename']));
$user->can = [];
check('no rights on the item: refused', refused(fn () => $writer->apply($ft, '17', '345', 't')) === 'permission');

echo "6. Skill text cannot carry a PHP open tag into the stored file\n";
$zip = new ZipArchive(); $zf = $root . '/s.zip'; $zip->open($zf, ZipArchive::CREATE);
$body = "Style text <?php file_put_contents(__DIR__ . '/PWNED', 'x'); ?> and <?= 1 ?> and <script>x</script>";
$zip->addFromString('SKILL.md', "---\nname: Payload\n---\n" . $body); $zip->close();
$store = new SkillStore($root); $skill = $store->install($zf, 's.zip');
$raw = file_get_contents($skill->folder . '/skill.php');
check('no "<?" after the guard line', substr_count($raw, '<?') === 1 && str_starts_with($raw, "<?php defined('_JEXEC') or die; ?>\n"));
shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg("define('_JEXEC', 1); include " . var_export($skill->folder . '/skill.php', true) . ';') . ' 2>/dev/null');
check('even included from inside Joomla, nothing runs', !is_file($skill->folder . '/PWNED'));
check('the text reads back exactly as it was written', $store->get('payload')->documents['SKILL.md'] === $body);

echo "7. Generated files stay inside images\n";
foreach (['tmp', 'administrator/logs', 'media/x', 'imagesx/a', '/etc/passwd', '../../etc', 'images/../configuration', 'libraries'] as $f) {
    check('folder setting ' . json_encode($f) . ' falls back to the default', (new Settings(new Registry(['save_folder' => $f])))->saveFolder() === 'images/ai-generated');
}
foreach (['images', 'images/ai', 'images/a/b-c_d'] as $f) {
    check('folder setting ' . json_encode($f) . ' is accepted', (new Settings(new Registry(['save_folder' => $f])))->saveFolder() === $f);
}

echo "8. What the service said stays out of the editor's message\n";
$openai = new OpenAiProvider($settings, new HttpClient(180)); $gemini = new GeminiProvider($settings, new HttpClient(180));
$try = function (callable $fn): array { try { $fn(); return ['', '', '']; } catch (AiImageException $e) { return [$e->getMessage(), $e->detail, $e->reason]; } };
$secret = 'Incorrect API key provided: sk-proj-****************abcd. You can find your API key at https://platform.openai.com/account/api-keys.';
HttpFactory::$next = ['status' => 401, 'json' => ['error' => ['message' => $secret, 'code' => 'invalid_api_key', 'type' => 'invalid_request_error']]];
[$msg, $detail] = $try(fn () => $openai->generate('p', Ratio::parse('1:1')));
check('OpenAI bad key: message names the kind of problem only', str_starts_with($msg, 'PLG_SYSTEM_GSSAIIMAGE_ERR_PROVIDER_AUTH') && !str_contains($msg, 'sk-proj') && !str_contains($msg, 'platform.openai.com') && str_contains($detail, 'sk-proj-****'), $msg);
foreach ([
    'OpenAI quota'    => [429, ['error' => ['message' => 'You exceeded your current quota, org-AbC123', 'code' => 'insufficient_quota']], 'QUOTA', 'org-AbC123'],
    'OpenAI blocked'  => [400, ['error' => ['message' => 'Your request was rejected by the safety system. request ID req_9f8e', 'code' => 'moderation_blocked']], 'BLOCKED', 'req_9f8e'],
    'OpenAI other'    => [400, ['error' => ['message' => 'Your organization must be verified to use the model gpt-image-2', 'code' => null]], 'FAILED', 'organization must be verified'],
    'OpenAI no body'  => [502, [], 'FAILED', 'HTTP 502'],
] as $label => [$status, $json, $kind, $inDetail]) {
    HttpFactory::$next = ['status' => $status, 'json' => $json];
    [$msg, $detail] = $try(fn () => $openai->generate('p', Ratio::parse('1:1')));
    check($label . ': ' . $kind . ', wording of the service only in the detail', str_starts_with($msg, 'PLG_SYSTEM_GSSAIIMAGE_ERR_PROVIDER_' . $kind) && !str_contains($msg, $inDetail) && str_contains($detail, $inDetail), [$msg, $detail]);
}
foreach ([
    'Gemini bad key' => [400, ['error' => ['message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT']], 'AUTH', 'API key not valid'],
    'Gemini quota'   => [429, ['error' => ['message' => 'Quota exceeded for project 1234567', 'status' => 'RESOURCE_EXHAUSTED']], 'QUOTA', 'project 1234567'],
    'Gemini other'   => [500, ['error' => ['message' => 'Internal error at backend xyz', 'status' => 'INTERNAL']], 'FAILED', 'backend xyz'],
] as $label => [$status, $json, $kind, $inDetail]) {
    HttpFactory::$next = ['status' => $status, 'json' => $json];
    [$msg, $detail] = $try(fn () => $gemini->generate('p', Ratio::parse('1:1')));
    check($label . ': ' . $kind . ', wording of the service only in the detail', str_starts_with($msg, 'PLG_SYSTEM_GSSAIIMAGE_ERR_PROVIDER_' . $kind) && !str_contains($msg, $inDetail) && str_contains($detail, $inDetail), [$msg, $detail]);
}
HttpFactory::$next = ['status' => 200, 'json' => ['promptFeedback' => ['blockReason' => 'SAFETY'], 'candidates' => [['content' => ['parts' => [['text' => 'I cannot draw that person.']]]]]]];
[$msg, $detail, $reason] = $try(fn () => $gemini->generate('p', Ratio::parse('1:1')));
check('Gemini safety block: said as such, the model\'s words only in the detail', str_starts_with($msg, 'PLG_SYSTEM_GSSAIIMAGE_ERR_PROVIDER_BLOCKED') && $reason === 'blocked' && !str_contains($msg, 'cannot draw') && str_contains($detail, 'SAFETY'));
HttpFactory::$next = ['throw' => 'cURL error 6: Could not resolve host: proxy.internal.example (10.0.3.7)'];
[$msg, $detail] = $try(fn () => $openai->generate('p', Ratio::parse('1:1')));
check('connection failure: no host names or addresses in the message', $msg === 'PLG_SYSTEM_GSSAIIMAGE_ERR_CONNECTION' && str_contains($detail, '10.0.3.7'));

echo "9. Time a request may take\n";
HttpFactory::$next = ['status' => 200, 'json' => ['choices' => [['message' => ['content' => '{}']]]]];
$openai->text('s', 'u');
check('OpenAI language model call: 45 seconds', HttpFactory::$next['sent']['t'] === 45);
HttpFactory::$next = ['status' => 200, 'json' => ['candidates' => [['content' => ['parts' => [['text' => '{}']]]]]]];
$gemini->text('s', 'u');
check('Gemini language model call: 45 seconds', HttpFactory::$next['sent']['t'] === 45);
HttpFactory::$next = ['status' => 200, 'json' => ['data' => [['b64_json' => base64_encode($webp)]]]];
$openai->generate('p', Ratio::parse('1:1'));
check('image call: the time set in the plugin', HttpFactory::$next['sent']['t'] === 180);
check('setting above 300 seconds is capped', (new Settings(new Registry(['timeout' => 900])))->timeout() === 300 && (new Settings(new Registry(['timeout' => 60])))->timeout() === 60);
$short = new Settings(new Registry(['openai_api_key' => 'k', 'timeout' => 30]));
HttpFactory::$next = ['status' => 200, 'json' => ['choices' => [['message' => ['content' => '{}']]]]];
(new OpenAiProvider($short, new HttpClient(30)))->text('s', 'u');
check('a shorter setting also shortens the language model call', HttpFactory::$next['sent']['t'] === 30);

exec('rm -rf ' . escapeshellarg($root));
echo "\n" . ($failures ? $failures . ' FAILED' : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
