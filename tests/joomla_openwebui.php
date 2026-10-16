<?php
/**
 * Talks to a real Open WebUI server through the real Joomla HTTP client.
 *
 *   php tests_gssaiimage/joomla_openwebui.php /path/to/joomla/site https://ai.example.com
 *
 * Without a key it checks that the server is reached and that a wrong key is reported as such.
 * With a key in the environment it goes the whole way once: picture idea, one real image with style references,
 * and one real revision. That costs what two images cost:
 *
 *   OPENWEBUI_API_KEY=... OPENWEBUI_IMAGE_MODEL=... OPENWEBUI_TEXT_MODEL=... php tests_gssaiimage/joomla_openwebui.php <site> <server>
 *
 * The key is read from the environment only. It is never printed.
 */
$site   = rtrim($argv[1] ?? '', '/');
$server = $argv[2] ?? '';

if (!is_file($site . '/configuration.php') || $server === '') {
    fwrite(STDERR, "Usage: php joomla_openwebui.php /path/to/joomla/site https://open-webui-server\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/administrator/index.php'; $_SERVER['SCRIPT_NAME'] = '/administrator/index.php'; $_SERVER['REQUEST_METHOD'] = 'GET';
define('_JEXEC', 1);
define('JPATH_BASE', $site . '/administrator');
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use Gss\Plugin\System\GssAiImage\Provider\OpenWebUiProvider;
use Gss\Plugin\System\GssAiImage\Service\{AiImageException, HttpClient, ImageProcessor, Ratio, Settings};
use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

$container = Factory::getContainer();
$container->alias('session', 'session.cli')->alias('JSession', 'session.cli')->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\Session::class, 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
Factory::$application = $app;
if (method_exists($app, 'createExtensionNamespaceMap')) { $app->createExtensionNamespaceMap(); }
$lang = $container->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('zh-TW', false);
$app->loadLanguage($lang); Factory::$language = $lang;

$dev = dirname(__DIR__) . '/plg_system_gssaiimage';
spl_autoload_register(function ($class) use ($dev) {
    $prefix = 'Gss\\Plugin\\System\\GssAiImage\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = $dev . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require $file; }
    }
}, true, true);
$lang->load('plg_system_gssaiimage', $dev, 'zh-TW', true);

$key  = (string) getenv('OPENWEBUI_API_KEY');
$real = $key !== '';
$settings = new Settings(new Registry([
    'openwebui_base_url'    => $server,
    'openwebui_api_key'     => $real ? $key : 'sk-not-a-real-key',
    'openwebui_image_model' => (string) getenv('OPENWEBUI_IMAGE_MODEL'),
    'openwebui_text_model'  => (string) getenv('OPENWEBUI_TEXT_MODEL'),
    'timeout'               => 240,
]));
$http     = new HttpClient(240);
$provider = new OpenWebUiProvider($settings, $http);
$failures = 0;
$check = function (string $label, bool $ok, $detail = '') use (&$failures) { echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : '  → ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE))) . "\n"; if (!$ok) { $failures++; } };
$attempt = function (callable $fn) { try { return ['value' => $fn()]; } catch (AiImageException $e) { return ['message' => $e->getMessage(), 'detail' => $e->detail, 'reason' => $e->reason]; } };

echo 'Joomla ' . (new \Joomla\CMS\Version())->getShortVersion() . ', PHP ' . PHP_VERSION . ', server ' . $settings->openWebUiBaseUrl() . "\n";

$version = $http->get($settings->openWebUiBaseUrl() . '/api/version', [], 20);
$check('server reached, Open WebUI ' . ($version['json']['version'] ?? '?'), $version['status'] === 200 && isset($version['json']['version']), $version['status']);
$del = $http->delete($settings->openWebUiBaseUrl() . '/api/v1/files/not-a-file', [], 20);
$check('delete requests go through the Joomla client (the server asks for a key: HTTP ' . $del['status'] . ')', in_array($del['status'], [401, 403], true));

if (!$real) {
    $r = $attempt(fn () => $provider->generate('test', Ratio::parse('1:1')));
    $check('a wrong key is told apart: ' . ($r['message'] ?? 'no error'), ($r['reason'] ?? '') === 'http_401' && !str_contains($r['message'], 'token'), $r);
    $check('what the server said is kept for the log: ' . ($r['detail'] ?? ''), str_contains($r['detail'] ?? '', 'HTTP 401'));
    $sample = sys_get_temp_dir() . '/gssai-sample-' . bin2hex(random_bytes(4)) . '.jpg'; $im = imagecreatetruecolor(640, 480); imagejpeg($im, $sample);
    $r = $attempt(fn () => $provider->generate('test', Ratio::parse('1:1'), [$sample]));
    unlink($sample);
    $check('same on the route that takes images', ($r['reason'] ?? '') === 'http_401', $r);
    echo "\nNo OPENWEBUI_API_KEY in the environment, so no image was made.\n";
} else {
    // The whole way an editor goes: article in, picture idea, image with the style references, then one revision
    $root = sys_get_temp_dir() . '/gssai-openwebui-' . date('Ymd-His');
    mkdir($root . '/images/style-reference', 0755, true);
    foreach ([1, 2] as $i) { $im = imagecreatetruecolor(1536, 1024); imagefill($im, 0, 0, imagecolorallocate($im, 40 * $i, 90, 200)); imagejpeg($im, $root . '/images/style-reference/style-0' . $i . '.jpg'); }

    $flow = new Settings(new Registry([
        'provider_default'      => 'openwebui',
        'openwebui_base_url'    => $server,
        'openwebui_api_key'     => $key,
        'openwebui_image_model' => (string) getenv('OPENWEBUI_IMAGE_MODEL'),
        'openwebui_text_model'  => (string) getenv('OPENWEBUI_TEXT_MODEL'),
        'max_reference_images'  => 2,
        'reference_folder'      => 'images/style-reference',
        'style_colors'          => '#1A5BFF, #7C3AED',
        'timeout'               => 240,
    ]));
    $generator = new \Gss\Plugin\System\GssAiImage\Service\Generator(
        $flow,
        new \Gss\Plugin\System\GssAiImage\Service\RuleResolver($flow, null),
        new \Gss\Plugin\System\GssAiImage\Service\DraftStore($flow, 42, $root),
        new \Gss\Plugin\System\GssAiImage\Service\PromptBuilder($flow),
        new ImageProcessor(),
        new \Gss\Plugin\System\GssAiImage\Service\ConceptWriter(),
        null,
        $root
    );
    $article = [
        'title'       => (string) (getenv('OPENWEBUI_TEST_TITLE') ?: '用標籤整理知識庫文章'),
        'content'     => '<p>知識庫的文章現在可以加上多個標籤。同一篇文章可以同時屬於幾個主題，讀者從任何一個主題都找得到它。</p>',
        'tags'        => ['知識庫', '標籤', '常見問題'],
        'layout_mode' => (string) (getenv('OPENWEBUI_TEST_LAYOUT') ?: 'dark'),
        'ratio'       => '40:23',
        'max_width'   => 1200,
    ];

    $t0 = microtime(true);
    $r  = $attempt(fn () => $generator->generate($article));
    $ok = isset($r['value']['draft']);
    $check('article to image with two style references, ' . round(microtime(true) - $t0) . ' s', $ok, $r);

    if ($ok) {
        $v = $r['value'];
        echo '       ' . $v['width'] . ' x ' . $v['height'] . ', ' . round($v['bytes'] / 1024) . ' KB, layout ' . $v['layout_mode'] . ', references ' . implode(' + ', $v['references']) . "\n";
        echo '       idea: ' . mb_substr($v['concept'], 0, 200) . "\n";
        echo '       notice: ' . ($v['notice'] ?: '(none)') . "\n";
        echo '       file: ' . $root . '/' . $v['path'] . "\n";
        $check('the references were used and the size is as asked', $v['notice'] === '' && count($v['references']) === 2 && $v['width'] === 1200 && $v['height'] === 690);

        $t0 = microtime(true);
        $e  = $attempt(fn () => $generator->generate(['title' => $article['title'], 'instruction' => '把背景的顏色改得再深一點，其他都不要動', 'base' => $v['draft'], 'layout_mode' => $v['layout_mode'], 'ratio' => '40:23', 'max_width' => 1200]));
        $ok = isset($e['value']['draft']);
        $check('revision of that image, ' . round(microtime(true) - $t0) . ' s', $ok && $e['value']['revised'], $e);
        if ($ok) { echo '       file: ' . $root . '/' . $e['value']['path'] . "\n"; }
    }
}

echo "\n" . ($failures ? $failures . ' FAILED' : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
