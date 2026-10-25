<?php

declare(strict_types=1);

/**
 * ═══ EKLENTİ, SDK'DA OLMAYAN BİR SINIFA BAŞVURUYOR MU? ═══
 *
 * 🔴 NEDEN VAR (ölçüldü 2026-09-28): SDK 0.11.0 `Lunixi\Sdk\Fraud\BlacklistClient`i
 *    kaldırdı. Eklenti ona `use` ile bağlıydı ve `composer.json` sürümü `"*"` idi,
 *    yani bir sonraki temiz kurulum sınıfı olmayan bir SDK çekiyordu. Sonuç: Fraud
 *    yönetim sayfası yarı çizilip beyaz ekran — sabit erişimi (`TYPE_EMAIL`)
 *    try bloğundan ÖNCEydi, yani yakalanmayan bir `Error`.
 *
 * 🔴 NEDEN `php -l` YETMEZ: sözdizimi denetimi sınıf ÇÖZMEZ. `composer.json`daki
 *    eski `lint` betiği (`php -l src`) bu hatayı göremezdi ve görmedi. Tek dürüst
 *    ölçüm, sınıfı autoloader üzerinden GERÇEKTEN çözmeye çalışmaktır.
 *
 * KAPSAM TÜRETİLİR: `src/` altındaki her dosyanın `use Lunixi\Sdk\...` satırları
 * taranır. Elle liste tutulsaydı, yarın eklenen dördüncü bağımlılık yine sessizce
 * kırılırdı.
 *
 * Kullanım:  composer run check
 */

$root = dirname(__DIR__);

$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "HATA: vendor/autoload.php yok — once `composer install` kosun.\n");
    exit(2);
}
require $autoload;

/** @return list<string> */
function phpFiles(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

$files = phpFiles($root . '/src');
if ($files === []) {
    // 🔴 BOŞ KÜME SESSİZCE YEŞİL KALMASIN: tarama hiçbir dosya bulamadıysa bu bir
    //    arızadır, "sorun yok" değildir.
    fwrite(STDERR, "HATA: src/ altinda PHP dosyasi bulunamadi — tarama bos dondu.\n");
    exit(2);
}

$referenced = [];   // sinif adi => [dosya:satir, ...]
foreach ($files as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*use\s+(Lunixi\\\\Sdk\\\\[A-Za-z0-9_\\\\]+)\s*(?:as\s+\w+\s*)?;/', $line, $m) === 1) {
            $referenced[$m[1]][] = basename(dirname($file)) . '/' . basename($file) . ':' . ($i + 1);
        }
    }
}

if ($referenced === []) {
    fwrite(STDERR, "HATA: hicbir `use Lunixi\\Sdk\\...` satiri bulunamadi — desen bozulmus olabilir.\n");
    exit(2);
}

$missing = [];
foreach ($referenced as $class => $where) {
    if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
        $missing[$class] = $where;
    }
}

printf("SDK sinif basvurusu: %d essiz sinif, %d dosya tarandi\n", count($referenced), count($files));

if ($missing !== []) {
    fwrite(STDERR, "\n🔴 SDK'DA OLMAYAN SINIFA BASVURU:\n");
    foreach ($missing as $class => $where) {
        fwrite(STDERR, sprintf("  %s\n      <- %s\n", $class, implode(', ', $where)));
    }
    fwrite(STDERR, "\nKurulu SDK surumunu `composer show lunixi/php-sdk` ile olcun.\n");
    exit(1);
}

echo "OK — basvurulan her SDK sinifi cozuldu.\n";
exit(0);
