<?php
/**
 * Stand-ins for the few Joomla classes the plugins touch, so their logic can run without a site.
 * Run the tests with: php tests_gssaiimage/security_test.php
 */
namespace Joomla\Registry { class Registry { private array $d; public function __construct($d = []) { $this->d = is_string($d) ? (json_decode($d, true) ?: []) : (json_decode(json_encode($d), true) ?: []); }
    public function get($k, $def = null) { $v = $this->d[$k] ?? null; return ($v === null || $v === '') ? $def : (is_array($v) ? json_decode(json_encode($v)) : $v); } } }
namespace Joomla\CMS\Language { class Text { public static function _($k) { return $k; } public static function sprintf($k, ...$a) { return $k . ' [' . implode(' | ', $a) . ']'; } } }
namespace Joomla\CMS\Uri { class Uri { public static function root() { return 'https://example.test/'; } } }
namespace Joomla\CMS\Component { class ComponentHelper { public static function getParams($c) { return new \Joomla\Registry\Registry(['file_path' => 'components/com_flexicontent/uploads', 'media_path' => 'components/com_flexicontent/medias']); } } }
namespace Joomla\CMS\User { class User { public $id = 42; public $guest = 0; public array $can = ['core.edit' => true]; public function authorise($a, $b = null) { return !empty($this->can[$a]); } } }
namespace Joomla\CMS { class Factory { public static function getDate($t = 'now', $tz = null) { return new class { public function toSql() { return gmdate('Y-m-d H:i:s'); } }; }
    public static function getApplication() { return new class { public function get($k, $d = null) { return $d; } }; } } }
namespace Joomla\CMS\Http {
    /**
     * Answers requests from a script. Set $next for one answer to every call, or fill $queue with
     * one answer per call. Every call is recorded in $calls, the last one also in $next['sent'].
     */
    class HttpFactory { public static $next; public static array $queue = []; public static array $calls = [];
        public static function getHttp() { return new class {
            public function post($u, $b, $h, $t) { return $this->answer('post', $u, $b, $h, $t); }
            public function get($u, $h = [], $t = null) { return $this->answer('get', $u, null, $h, $t); }
            public function delete($u, $h = [], $t = null) { return $this->answer('delete', $u, null, $h, $t); }
            private function answer($m, $u, $b, $h, $t) {
                HttpFactory::$calls[] = compact('m', 'u', 'b', 'h', 't');
                $a = HttpFactory::$queue ? array_shift(HttpFactory::$queue) : HttpFactory::$next;
                if (is_array(HttpFactory::$next)) { HttpFactory::$next['sent'] = compact('u', 'b', 'h', 't'); }
                if (isset($a['throw'])) { throw new \RuntimeException($a['throw']); }
                return new class($a) { public function __construct(private array $a) {} public function getBody() { return $this->a['body'] ?? json_encode($this->a['json'] ?? []); } public function getStatusCode() { return $this->a['status']; } };
            } }; } }
}
namespace Joomla\Session { interface SessionInterface {} }
namespace Joomla\Database { class ParameterType { const INTEGER = 'int'; const STRING = 'string'; } interface DatabaseInterface {} }
namespace {
    define('_JEXEC', 1);
    spl_autoload_register(function ($class) {
        foreach (['Gss\\Plugin\\System\\GssAiImageFlexi\\' => 'plg_system_gssaiimageflexi', 'Gss\\Plugin\\System\\GssAiImage\\' => 'plg_system_gssaiimage'] as $prefix => $dir) {
            if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                $file = dirname(__DIR__) . '/' . $dir . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) { require $file; }
                return;
            }
        }
    });
    /** Answers field and item look-ups. Has no user_profiles table, so the ledger falls back to the session. */
    class FakeDb implements \Joomla\Database\DatabaseInterface { public array $inserted = []; public array $rows = [];
        public function getQuery($n = false) { return new class { public function __call($n, $a) { return $this; } }; }
        public function quoteName($n, $as = null) { return is_array($n) ? $n : '`' . $n . '`'; } public function quote($v) { return "'" . $v . "'"; }
        public function setQuery($q) { return $this; } public function loadObject() { return $this->rows['object'] ?? null; } public function loadResult() { return $this->rows['result'] ?? null; }
        public function transactionStart() { throw new \RuntimeException('no transactions here'); } public function transactionRollback() {}
        public function getTableColumns($t) { return $this->rows['columns'] ?? []; } public function insertObject($t, $o) { $this->inserted[] = [$t, (array) $o]; return true; } }
    class FakeSession implements \Joomla\Session\SessionInterface { public array $d = []; public function get($k, $def = null) { return $this->d[$k] ?? $def; } public function set($k, $v) { $this->d[$k] = $v; } }
    $failures = 0;
    function check(string $label, bool $ok, $detail = ''): void { global $failures; echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : '  → ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE))) . "\n"; if (!$ok) { $failures++; } }
    /** Writes a few landscape JPEGs, each a different colour, to stand in for style reference images. */
    function sampleImages(string $dir, int $count = 3): array { if (!is_dir($dir)) { mkdir($dir, 0755, true); } $files = []; foreach (range(1, $count) as $i) { $im = imagecreatetruecolor(1536, 1024); imagefill($im, 0, 0, imagecolorallocate($im, 40 * $i, 90, 200)); $files[] = $dir . '/style-0' . $i . '.jpg'; imagejpeg($im, end($files)); } return $files; }
    function refused(callable $fn): string { try { $fn(); return ''; } catch (\Gss\Plugin\System\GssAiImage\Service\AiImageException $e) { return $e->reason ?: 'refused'; } }
}
