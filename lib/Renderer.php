<?php
/**
 * Headless browser rendering (Microsoft Edge, Chromium-based — same engine Puppeteer/Playwright drive).
 * - check(): loads the page at 1080x1350 and reads the fit measurements the page writes into <body data-fit>
 * - png():   captures a 1080x1350 PNG
 */
class Renderer
{
    public const W = 1080;
    public const H = 1350;

    public const CHECKER = <<<'JS'
<script data-fit-checker>
window.addEventListener('load',function(){setTimeout(function(){
  var de=document.documentElement,b=document.body;
  var clipped=[].slice.call(document.querySelectorAll('[data-fit-box]')).filter(function(e){return e.scrollHeight>e.clientHeight+2||e.scrollWidth>e.clientWidth+2}).map(function(e){return e.getAttribute('data-fit-box')});
  var styled=getComputedStyle(b).margin!=='8px'; // Tailwind preflight ran
  b.setAttribute('data-fit',JSON.stringify({w:Math.max(de.scrollWidth,b.scrollWidth),h:Math.max(de.scrollHeight,b.scrollHeight),clipped:clipped,tailwind:styled}));
},700)});
</script>
JS;

    public static function edge(): ?string
    {
        foreach ([app_settings()['edge_path'], 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
                  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'] as $p) {
            if ($p && is_file($p)) return $p;
        }
        return null;
    }

    private static function runBrowser(array $args, int $timeout = 60): string
    {
        $exe = self::edge();
        if (!$exe) throw new RuntimeException('ไม่พบ Microsoft Edge/Chrome สำหรับเรนเดอร์ภาพ');
        $profile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adsintel-' . bin2hex(random_bytes(4));
        $cmd = array_merge([$exe, '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--disable-extensions', '--hide-scrollbars', '--force-device-scale-factor=1', "--user-data-dir=$profile",
            '--window-size=' . self::W . ',' . self::H, '--virtual-time-budget=6000', '--allow-file-access-from-files'], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('เปิด Edge ไม่สำเร็จ');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $start = time();
        while (true) {
            $out .= stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            $st = proc_get_status($proc);
            if (!$st['running']) break;
            if (time() - $start > $timeout) { proc_terminate($proc); break; }
            usleep(100000);
        }
        $out .= stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($proc);
        self::rmrf($profile);
        return $out;
    }

    private static function url(string $path): string
    {
        return 'file:///' . str_replace('\\', '/', realpath($path));
    }

    /** @return array{w:int,h:int,clipped:array,tailwind:bool,fits:bool} */
    public static function check(string $htmlPath): array
    {
        $dom = self::runBrowser(['--dump-dom', self::url($htmlPath)]);
        if (!preg_match('/data-fit="([^"]*)"/', $dom, $m)) {
            return ['w' => 0, 'h' => 0, 'clipped' => [], 'tailwind' => false, 'fits' => false, 'error' => 'ไม่ได้ผลการวัดจากเบราว์เซอร์'];
        }
        $fit = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true) ?: [];
        $fit += ['w' => 0, 'h' => 0, 'clipped' => [], 'tailwind' => false];
        $fit['fits'] = $fit['w'] <= self::W && $fit['h'] <= self::H && !$fit['clipped'] && $fit['tailwind'];
        return $fit;
    }

    public static function png(string $htmlPath, string $pngPath): bool
    {
        @unlink($pngPath);
        self::runBrowser(['--screenshot=' . $pngPath, self::url($htmlPath)]);
        return is_file($pngPath) && filesize($pngPath) > 1000;
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
    }
}
