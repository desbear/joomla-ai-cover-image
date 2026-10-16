<?php
/**
 * The Open WebUI image service, against scripted answers shaped like those of Open WebUI 0.11.
 */
require __DIR__ . '/stubs.php';

use Gss\Plugin\System\GssAiImage\Provider\{GeneratedImage, OpenWebUiProvider, ProviderInterface};
use Gss\Plugin\System\GssAiImage\Service\{AiImageException, ConceptWriter, DraftStore, Generator, HttpClient, ImageProcessor, PromptBuilder, Ratio, RuleResolver, Settings, SkillPromptWriter, SkillStore};
use Joomla\CMS\Http\HttpFactory;
use Joomla\Registry\Registry;

$root = sys_get_temp_dir() . '/gssai-owui-' . bin2hex(random_bytes(4));
sampleImages($root . '/images/style-reference');
define('JPATH_ROOT', $root);
$img = imagecreatetruecolor(1536, 1024); imagefill($img, 0, 0, imagecolorallocate($img, 247, 250, 255)); ob_start(); imagepng($img); $png = ob_get_clean();
file_put_contents($root . '/ref.jpg', $png); file_put_contents($root . '/base.webp', $png);

$params   = ['openwebui_base_url' => 'https://ai.example.com/api/v1/', 'openwebui_api_key' => 'sk-owui', 'openwebui_image_model' => 'gpt-image-1', 'openwebui_text_model' => 'gpt-4o-mini', 'provider_default' => 'openwebui'];
$settings = new Settings(new Registry($params));
$provider = new OpenWebUiProvider($settings, new HttpClient(180));
$file     = ['id' => '0b6f2c1e-8a44-4f7e-9d21-5c3e7a9b1f00', 'url' => '/api/v1/files/0b6f2c1e-8a44-4f7e-9d21-5c3e7a9b1f00/content', 'name' => 'generated-image.png', 'content_type' => 'image/png'];
$reset    = function (array $queue) { HttpFactory::$queue = $queue; HttpFactory::$calls = []; HttpFactory::$next = null; };
$try      = function (callable $fn): array { try { $fn(); return ['', '', '']; } catch (AiImageException $e) { return [$e->getMessage(), $e->detail, $e->reason]; } };

echo "Settings\n";
check('address with /api/v1 pasted is reduced to the server', $settings->openWebUiBaseUrl() === 'https://ai.example.com');
foreach (['https://ai.example.com' => 'https://ai.example.com', 'https://ai.example.com/api' => 'https://ai.example.com', 'http://10.0.0.5:3000/' => 'http://10.0.0.5:3000', 'https://host/sub/path/api/v1' => 'https://host/sub/path',
    'ftp://ai.example.com' => '', 'https://user:pw@ai.example.com' => '', 'ai.example.com' => '', 'javascript:alert(1)' => '', 'https://ai.example.com/?x=1' => ''] as $in => $out) {
    check('address ' . json_encode($in) . ' gives ' . json_encode($out), (new Settings(new Registry(['openwebui_base_url' => $in])))->openWebUiBaseUrl() === $out);
}
check('ready needs both the address and the key', $settings->providerReady('openwebui') && !(new Settings(new Registry(['openwebui_api_key' => 'k'])))->providerReady('openwebui') && !(new Settings(new Registry(['openwebui_base_url' => 'https://x'])))->providerReady('openwebui'));
check('it can be the default service', $settings->defaultProvider() === 'openwebui' && (new Settings(new Registry(['provider_default' => 'openwebui', 'openai_api_key' => 'k'])))->defaultProvider() === 'openai');
check('no model set: none sent, the server decides', (new Settings(new Registry([])))->imageModel('openwebui') === '' && (new Settings(new Registry([])))->textModel('openwebui') === '');

echo "Image from a prompt alone\n";
$reset([['status' => 200, 'json' => [$file]], ['status' => 200, 'body' => $png], ['status' => 200, 'json' => ['message' => 'ok']]]);
$image = $provider->generate('a prompt', Ratio::parse('16:9'));
[$post, $get, $del] = HttpFactory::$calls;
$body = json_decode($post['b'], true);
check('posts to /api/v1/images/generations with the key', $post['m'] === 'post' && $post['u'] === 'https://ai.example.com/api/v1/images/generations' && $post['h']['Authorization'] === 'Bearer sk-owui' && $post['t'] === 180);
check('body: prompt, size for the ratio, one image, the model', $body === ['prompt' => 'a prompt', 'size' => '1536x1024', 'n' => 1, 'model' => 'gpt-image-1'], $body);
check('fetches the file from the same server with the key', $get['m'] === 'get' && $get['u'] === 'https://ai.example.com/api/v1/files/0b6f2c1e-8a44-4f7e-9d21-5c3e7a9b1f00/content' && $get['h']['Authorization'] === 'Bearer sk-owui');
check('returns the image as it came', $image->data === $png && $image->model === 'gpt-image-1' && $image->imagesUsed === false);
check('then deletes the file on the server', $del['m'] === 'delete' && $del['u'] === 'https://ai.example.com/api/v1/files/0b6f2c1e-8a44-4f7e-9d21-5c3e7a9b1f00');
$reset([['status' => 200, 'json' => [$file]], ['status' => 200, 'body' => $png]]);
(new OpenWebUiProvider(new Settings(new Registry($params + ['openwebui_delete_files' => 0])), new HttpClient(180)))->generate('p', Ratio::parse('4:5'));
check('deleting can be switched off, and portrait asks for 1024x1536', count(HttpFactory::$calls) === 2 && json_decode(HttpFactory::$calls[0]['b'], true)['size'] === '1024x1536');
$reset([['status' => 200, 'json' => ['data' => [['b64_json' => base64_encode($png)]]]]]);
check('an answer in the OpenAI shape is understood too', $provider->generate('p', Ratio::parse('1:1'))->data === $png && count(HttpFactory::$calls) === 1);
$reset([['status' => 200, 'json' => [$file]], ['status' => 200, 'body' => $png], ['throw' => 'timeout']]);
check('a failed delete does not fail the image', $provider->generate('p', Ratio::parse('1:1'))->data === $png);

echo "Image with reference images\n";
$reset([['status' => 200, 'json' => [$file]], ['status' => 200, 'body' => $png], ['status' => 200, 'json' => []]]);
$image = $provider->generate('a prompt', Ratio::parse('16:9'), [$root . '/ref.jpg'], $root . '/base.webp');
$body  = json_decode(HttpFactory::$calls[0]['b'], true);
check('posts to /api/v1/images/edit', HttpFactory::$calls[0]['u'] === 'https://ai.example.com/api/v1/images/edit');
check('revising: the image to revise goes alone, as one data URL, and the reference is reported as not used', is_string($body['image']) && str_starts_with($body['image'], 'data:image/webp;base64,') && $body['prompt'] === 'a prompt' && $body['model'] === 'gpt-image-1' && $body['size'] === '1536x1024' && $image->imagesUsed === false);
$reset([['status' => 200, 'json' => [$file]], ['status' => 200, 'body' => $png], ['status' => 200, 'json' => []]]);
$refs  = glob($root . '/images/style-reference/*.jpg');
$image = $provider->generate('a prompt', Ratio::parse('16:9'), [$refs[0], $refs[1], $refs[2]]);
$body  = json_decode(HttpFactory::$calls[0]['b'], true);
$sheet = getimagesizefromstring(base64_decode(substr($body['image'], strlen('data:image/jpeg;base64,'))));
check('new image: three references become one sheet, never a list (a list is refused by the upstream of Open WebUI)', is_string($body['image']) && str_starts_with($body['image'], 'data:image/jpeg;base64,') && $sheet !== false && max($sheet[0], $sheet[1]) <= 1600 && $image->imagesUsed, $sheet);
check('and the prompt says how to read the sheet', str_starts_with($body['prompt'], 'Input image: the one supplied image is a sheet that holds 3 separate reference images') && str_ends_with($body['prompt'], "\na prompt") && str_contains($body['prompt'], 'do not draw a sheet'));
$reset([['status' => 200, 'json' => [$file]], ['status' => 200, 'body' => $png], ['status' => 200, 'json' => []]]);
$image = $provider->generate('a prompt', Ratio::parse('16:9'), [$refs[0]]);
$body  = json_decode(HttpFactory::$calls[0]['b'], true);
check('one reference goes as it is, with nothing added to the prompt', is_string($body['image']) && $body['image'] === 'data:image/jpeg;base64,' . base64_encode(file_get_contents($refs[0])) && $body['prompt'] === 'a prompt' && $image->imagesUsed);
$reset([['status' => 200, 'json' => [$file]], ['status' => 200, 'body' => $png], ['status' => 200, 'json' => []]]);
$provider->generate('把杯子上的 COFFEE 改成 TEA', Ratio::parse('1:1'), [], $root . '/base.webp');
$body = json_decode(HttpFactory::$calls[0]['b'], true);
check('revising one image sends it as a plain data URL, the request seen to work on Open WebUI 0.11', HttpFactory::$calls[0]['u'] === 'https://ai.example.com/api/v1/images/edit' && is_string($body['image']) && str_starts_with($body['image'], 'data:image/webp;base64,') && array_keys($body) === ['image', 'prompt', 'size', 'n', 'model'] && $body['size'] === '1024x1024' && $body['n'] === 1 && HttpFactory::$calls[0]['h']['Content-Type'] === 'application/json', array_keys($body));
$reset([['status' => 403, 'json' => ['detail' => 'You do not have permission to access this resource.']], ['status' => 200, 'json' => [$file]], ['status' => 200, 'body' => $png], ['status' => 200, 'json' => []]]);
$image = $provider->generate('a prompt', Ratio::parse('16:9'), [$root . '/ref.jpg']);
check('editing switched off on the server: a new image is made from the prompt alone', HttpFactory::$calls[1]['u'] === 'https://ai.example.com/api/v1/images/generations' && !isset(json_decode(HttpFactory::$calls[1]['b'], true)['image']) && $image->imagesUsed === false && $image->data === $png);
$reset([['status' => 403, 'json' => ['detail' => 'You do not have permission to access this resource.']]]);
[$msg, $detail, $reason] = $try(fn () => $provider->generate('p', Ratio::parse('1:1'), [], $root . '/base.webp'));
check('but revising an image is refused with a clear message', $reason === 'no_edit' && $msg === 'PLG_SYSTEM_GSSAIIMAGE_ERR_NO_EDIT' && count(HttpFactory::$calls) === 1 && str_contains($detail, 'HTTP 403'));

echo "The key never leaves the configured server\n";
foreach (['https://evil.example/api/v1/files/x/content', 'https://ai.example.com.evil.example/f', 'https://ai.example.com@evil.example/f', 'http://ai.example.com/api/v1/files/x/content', 'https://ai.example.com:8443/f', '//evil.example/f'] as $url) {
    $reset([['status' => 200, 'json' => [['id' => 'abcdef12', 'url' => $url]]], ['status' => 200, 'body' => $png]]);
    [$msg, $detail, $reason] = $try(fn () => $provider->generate('p', Ratio::parse('1:1')));
    check('file address ' . $url . ' is not fetched', $reason === 'foreign_url' && count(HttpFactory::$calls) === 1 && !str_contains($msg, 'evil'));
}
$reset([['status' => 200, 'json' => [['id' => 'abcdef12', 'url' => 'https://AI.EXAMPLE.COM:443/api/v1/files/abcdef12/content']]], ['status' => 200, 'body' => $png], ['status' => 200, 'json' => []]]);
check('the same server written in full is fetched', $provider->generate('p', Ratio::parse('1:1'))->data === $png);
$reset([['status' => 200, 'json' => [['id' => '../../users/1', 'url' => '/api/v1/files/x/content']]], ['status' => 200, 'body' => $png]]);
$provider->generate('p', Ratio::parse('1:1'));
check('an odd file id is never put into a delete address', count(HttpFactory::$calls) === 2);

echo "Errors\n";
foreach ([
    'bad key'        => [401, ['detail' => 'Your session has expired or the token is invalid. Please sign in again.'], 'AUTH', 'token is invalid'],
    'no permission'  => [403, ['detail' => 'You do not have permission to access this resource.'], 'DENIED', 'permission to access'],
    'too many'       => [429, ['detail' => 'Rate limit of user 8f3a exceeded'], 'QUOTA', 'user 8f3a'],
    'upstream error' => [400, ['detail' => "[ERROR: 400, message='Bad Request', url='https://internal-litellm.gss.local/v1/images/generations']"], 'FAILED', 'internal-litellm.gss.local'],
    'gateway page'   => [502, null, 'FAILED', 'Bad Gateway'],
] as $label => [$status, $json, $kind, $inDetail]) {
    $reset([$json === null ? ['status' => $status, 'body' => '<html><h1>502 Bad Gateway</h1></html>'] : ['status' => $status, 'json' => $json]]);
    [$msg, $detail] = $try(fn () => $provider->generate('p', Ratio::parse('1:1')));
    check($label . ': ' . $kind . ', what the server said only in the detail', str_starts_with($msg, 'PLG_SYSTEM_GSSAIIMAGE_ERR_PROVIDER_' . $kind) && !str_contains($msg, $inDetail) && str_contains($detail, $inDetail), [$msg, $detail]);
}
$reset([['status' => 200, 'json' => []]]);
[$msg, , $reason] = $try(fn () => $provider->generate('p', Ratio::parse('1:1')));
check('an empty answer is reported as no image', $reason === 'empty' && str_starts_with($msg, 'PLG_SYSTEM_GSSAIIMAGE_ERR_NO_IMAGE'));
$reset([['status' => 200, 'json' => [$file]], ['status' => 404, 'json' => ['detail' => 'Not Found']]]);
[$msg, $detail] = $try(fn () => $provider->generate('p', Ratio::parse('1:1')));
check('a file that cannot be fetched is reported', str_starts_with($msg, 'PLG_SYSTEM_GSSAIIMAGE_ERR_PROVIDER_FAILED') && str_contains($detail, '404'));

echo "Language model\n";
$reset([['status' => 200, 'json' => ['choices' => [['message' => ['content' => '{"topic":"t"}']]]]]]);
$text = $provider->text('system text', 'user text');
$body = json_decode(HttpFactory::$calls[0]['b'], true);
check('chat completions with the model id of Open WebUI, 45 seconds', HttpFactory::$calls[0]['u'] === 'https://ai.example.com/api/v1/chat/completions' && $body['model'] === 'gpt-4o-mini' && $body['stream'] === false && !isset($body['response_format']) && $body['messages'][0] === ['role' => 'system', 'content' => 'system text'] && HttpFactory::$calls[0]['t'] === 45 && $text === '{"topic":"t"}');
$noText = new OpenWebUiProvider(new Settings(new Registry(['openwebui_text_model' => ''] + $params)), new HttpClient(180));
$reset([]);
check('no language model set: nothing is sent', $try(fn () => $noText->text('s', 'u'))[2] === 'no_text_model' && HttpFactory::$calls === []);

echo "Whole flow through the generator\n";
$make = function (array $extra = []) use ($params, $root) {
    $s = new Settings(new Registry($extra + $params + ['max_reference_images' => 2, 'reference_folder' => 'images/style-reference']));
    return new Generator($s, new RuleResolver($s, null), new DraftStore($s, 42, $root), new PromptBuilder($s), new ImageProcessor(), new ConceptWriter(), null, $root, new SkillStore($root), new SkillPromptWriter());
};
$concept = ['status' => 200, 'json' => ['choices' => [['message' => ['content' => '{"topic":"faster approval","subject":"Contract sheets move through approval nodes.","layout_mode":"collage"}']]]]];
$reset([$concept, ['status' => 200, 'json' => [$file]], ['status' => 200, 'body' => $png], ['status' => 200, 'json' => []]]);
$r = $make()->generate(['title' => '如何縮短合約審核的流程', 'content' => '<p>內文</p>']);
check('idea from the language model, image through the edit route with two style references on one sheet', array_column(HttpFactory::$calls, 'u') === ['https://ai.example.com/api/v1/chat/completions', 'https://ai.example.com/api/v1/images/edit', 'https://ai.example.com/api/v1/files/' . $file['id'] . '/content', 'https://ai.example.com/api/v1/files/' . $file['id']] && is_string(json_decode(HttpFactory::$calls[1]['b'], true)['image']) && str_contains(json_decode(HttpFactory::$calls[1]['b'], true)['prompt'], 'a sheet that holds 2 separate reference images'));
check('draft stored, cropped to 16:9', $r['provider'] === 'openwebui' && $r['model'] === 'gpt-image-1' && $r['width'] === 1200 && $r['height'] === 675 && is_file($root . '/' . $r['path']) && $r['notice'] === '' && count($r['references']) === 2);
$reset([$concept, ['status' => 403, 'json' => ['detail' => 'no']], ['status' => 200, 'json' => [$file]], ['status' => 200, 'body' => $png], ['status' => 200, 'json' => []]]);
$r = $make()->generate(['title' => '標題', 'content' => '<p>內文</p>']);
check('server without editing: image made, and the editor is told the references were not used', $r['notice'] === 'PLG_SYSTEM_GSSAIIMAGE_NOTICE_IMAGES_IGNORED' && $r['references'] === [] && $r['editor_references'] === 0 && is_file($root . '/' . $r['path']));
$reset([['status' => 200, 'json' => [$file]], ['status' => 200, 'body' => $png], ['status' => 200, 'json' => []]]);
$r = $make(['openwebui_text_model' => '', 'use_reference_images' => 0])->generate(['title' => '標題', 'content' => '<p>內文</p>']);
check('no language model and no references: one generation call, article brief in the prompt', HttpFactory::$calls[0]['u'] === 'https://ai.example.com/api/v1/images/generations' && str_contains($r['prompt'], 'Article brief:') && $r['notice'] === '');
$reset([['status' => 200, 'json' => [$file]], ['status' => 200, 'body' => 'this is not an image'], ['status' => 200, 'json' => []]]);
check('a file that is not an image is refused before it is stored', refused(fn () => $make(['openwebui_text_model' => '', 'use_reference_images' => 0])->generate(['title' => '標題'])) === 'decode');

exec('rm -rf ' . escapeshellarg($root));
echo "\n" . ($failures ? $failures . ' FAILED' : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
