<?php
/**
 * =====================================================================
 *  YAPILANDIRMA DOSYASI
 *  cilginyazilim.com – Sürükle-Bırak Görev Panosu
 * ---------------------------------------------------------------------
 *  Bu dosya üç iş yapar:
 *    1. Oturumu (session) başlatır  → CSRF anahtarını saklamak için
 *    2. Ayarları sabit olarak tanımlar
 *    3. Veritabanı bağlantısını ($db) kurar
 *
 *  index.php ve system/ajax.php dosyalarının ikisi de bunu çağırır.
 *
 *  KENDİ SUNUCUNUZA UYARLAMAK İÇİN: Aşağıdaki DB_* satırlarını
 *  düzenleyin ya da sunucunuzda ortam değişkeni tanımlayın.
 * =====================================================================
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------
 *  .env DESTEĞİ
 * ---------------------------------------------------------------------
 *  Veritabanı bilgileri bu dosyanın İÇİNDE durmak zorunda değil.
 *  Depo kökündeki ".env" dosyasına yazarsanız buradaki varsayılanlar
 *  devreye girmez — ve ".env" .gitignore içinde olduğu için parolanız
 *  depoya hiç girmez.
 *
 *  NEDEN AYRI BİR DOSYA?
 *  config.php DEPODA durur ve her dağıtımda depodaki sürümle
 *  DEĞİŞTİRİLİR; içine elle yazdığınız parola bir sonraki deploy'da
 *  silinir. .env ise deploy'un dokunmadığı bir dosyadır: bir kez
 *  oluşturursunuz, kalıcıdır.
 *
 *  DEĞER ARAMA SIRASI
 *      1. config.local.php içinde define() edilmişse o kazanır
 *         (bu dosyada varsa; aşağıdaki "! defined()" kontrolleri)
 *      2. .env dosyası
 *      3. Sunucunun gerçek ortam değişkeni (Apache SetEnv, systemd…)
 *      4. Bu dosyadaki varsayılan
 *
 *  cy_env() bilerek getenv() ile AYNI şeyi döndürür (değer ya da
 *  false). Böylece aşağıdaki satırlar olduğu gibi çalışmaya devam
 *  eder; "?:" ve "!== false" kalıplarının hiçbiri değişmedi.
 * ------------------------------------------------------------------ */
if (! function_exists('cy_env')) {
    /**
     * .env dosyasından (yoksa ortamdan) bir değer okur.
     *
     * @return string|false Değer yoksa false — getenv() ile aynı sözleşme.
     */
    function cy_env(string $key): string|false
    {
        static $env = null;

        if ($env === null) {
            $env  = [];
            $file = dirname(__DIR__) . '/.env';

            if (is_file($file) && is_readable($file)) {
                /* IGNORE_NEW_LINES + SKIP_EMPTY_LINES: satır sonlarını ve
                 * boş satırları baştan eler; ayrıştırma sadeleşir. */
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                foreach ($lines as $line) {
                    $line = trim($line);

                    // Yorum satırı ya da "=" içermeyen satır atlanır.
                    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                        continue;
                    }

                    [$name, $value] = explode('=', $line, 2);

                    $name  = trim($name);
                    $value = trim($value);

                    /* Tırnak içindeki değerlerden tırnakları at:
                     * DB_PASS="a b c" → a b c
                     * Tırnak zorunlu değildir; yalnızca boşluk içeren
                     * parolalar için gerekir. */
                    if (strlen($value) >= 2
                        && ($value[0] === '"' || $value[0] === "'")
                        && $value[strlen($value) - 1] === $value[0]
                    ) {
                        $value = substr($value, 1, -1);
                    }

                    if ($name !== '') {
                        $env[$name] = $value;
                    }
                }
            }
        }

        // .env'de varsa o; yoksa sunucunun gerçek ortam değişkeni.
        return $env[$key] ?? getenv($key);
    }
}

/* ---------------------------------------------------------------------
 *  1) OTURUM
 * ---------------------------------------------------------------------
 *  Çerez ayarları session_start()'tan ÖNCE verilmelidir; sonrasında
 *  yapılan değişiklik o oturumu etkilemez.
 *
 *  Bu üç ayar, CSRF anahtarını taşıyan oturum çerezini korur:
 *
 *  httponly => JavaScript çerezi OKUYAMAZ. Sayfada bir XSS açığı
 *              oluşsa bile saldırgan oturumu çalıp kendi sunucusuna
 *              gönderemez.
 *
 *  samesite => 'Lax': Çerez, BAŞKA bir siteden gelen POST isteklerine
 *              eklenmez. Bu, CSRF anahtarının yanında ikinci bir
 *              savunma katmanıdır — biri atlatılsa diğeri durur.
 *              'Strict' seçilmedi: harici bir bağlantıyla gelen
 *              kullanıcı oturumunu kaybetmiş gibi görünürdü.
 *
 *  secure   => Çerez yalnızca HTTPS üzerinden gönderilir. Sabit "true"
 *              yazmak, düz HTTP ile çalışan yerel kurulumda oturumu
 *              tamamen bozardı; bu yüzden bağlantıya bakıp karar
 *              veriyoruz.
 * ------------------------------------------------------------------ */
if (session_status() === PHP_SESSION_NONE) {
    // HTTPS mi? Vekil sunucu (Cloudflare, nginx) arkasında $_SERVER['HTTPS']
    // boş kalır; bu durumda vekilin eklediği başlığa bakılır.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isHttps,
        'path'     => '/',
    ]);

    session_start();
}

/* ---------------------------------------------------------------------
 *  2) VERİTABANI AYARLARI
 * ---------------------------------------------------------------------
 *  cy_env('DB_HOST') ?: '127.0.0.1'
 *      → Sunucuda DB_HOST ortam değişkeni tanımlıysa onu kullan,
 *        tanımlı değilse (veya boşsa) '127.0.0.1' kullan.
 *
 *  Şifreleri koda yazıp GitHub'a yüklemek en sık yapılan güvenlik
 *  hatasıdır. Ortam değişkeni kullanırsanız aynı kod, farklı
 *  sunucularda farklı şifrelerle çalışır ve şifre repoda görünmez.
 * ------------------------------------------------------------------ */
define('DB_HOST', cy_env('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', cy_env('DB_NAME') ?: 'cy_todo');
define('DB_USER', cy_env('DB_USER') ?: 'root');
define('DB_PASS', cy_env('DB_PASS') !== false ? (string) cy_env('DB_PASS') : '');

// utf8mb4: Türkçe karakterler ve emoji dahil tüm Unicode'u destekler.
define('DB_CHARSET', 'utf8mb4');

/* ---------------------------------------------------------------------
 *  ZAMAN DİLİMİ
 * ---------------------------------------------------------------------
 *  ÖLÇÜLEN SORUN: php.ini'de date.timezone çoğu XAMPP kurulumunda
 *  sunucunun coğrafi diliminden farklıdır. Bu makinede PHP
 *  "Europe/Berlin", MySQL ise sistem dilimi (Europe/Istanbul)
 *  kullanıyordu; aynı anı anlatan iki satır BİR SAAT farklı görünüyordu:
 *
 *      worker günlüğü (PHP date)  : 14:03:17
 *      veritabanı  (MySQL NOW())  : 15:03:17
 *
 *  Bu depodaki zaman ARİTMETİĞİ bilinçli olarak SQL tarafında yapılır
 *  (NOW(), INTERVAL, TIMESTAMPDIFF), bu yüzden hesaplar zaten doğrudur.
 *  Kayan şey, PHP'nin ekrana/günlüğe bastığı saatti — ve demoyu
 *  deneyen biri için bu, "sistem yanlış çalışıyor" gibi görünür.
 *
 *  Çözüm: dilimi ORTAMA bırakmak yerine açıkça sabitliyoruz. Kendi
 *  sunucunuzda farklı bir dilim istiyorsanız APP_TIMEZONE ortam
 *  değişkenini tanımlamanız yeterlidir; kod değiştirmenize gerek yok.
 * ------------------------------------------------------------------ */
define('APP_TIMEZONE', cy_env('APP_TIMEZONE') ?: 'Europe/Istanbul');

// @ kullanmıyoruz: geçersiz bir dilim adı sessizce yutulmamalı.
if (in_array(APP_TIMEZONE, timezone_identifiers_list(), true)) {
    date_default_timezone_set(APP_TIMEZONE);
}

/* ---------------------------------------------------------------------
 *  3) UYGULAMA AYARLARI
 * ------------------------------------------------------------------ */

/**
 * APP_DEBUG
 *   true  → Hata detayları ekranda gösterilir (GELİŞTİRME için)
 *   false → Hatalar gizlenir, sadece log'a yazılır (CANLI için)
 *
 * NEDEN SABİT "true" YAZMIYORUZ?
 * Depodaki dosyada açık bırakılan bir hata ayıklama anahtarı, canlıya
 * alırken kapatılması UNUTULAN şeylerin başında gelir. Unutulduğunda
 * ise veritabanı hataları — tablo adları, sorgu metinleri, dosya
 * yolları — doğrudan ziyaretçinin ekranına yazılır ve saldırgana
 * sistemin haritasını çıkarır.
 *
 * Bu yüzden karar bir insana değil, ORTAMA bırakıldı:
 *   1. DB_* gibi APP_DEBUG de ortam değişkeninden okunabilir
 *   2. Değişken tanımlı değilse: yalnızca YEREL adreslerde açılır
 * Sonuç: geliştirici bilgisayarında hatalar görünür, aynı dosya
 * bir sunucuya çıktığında kendiliğinden susar.
 */
$debugEnv = cy_env('APP_DEBUG');

if ($debugEnv !== false && $debugEnv !== '') {
    // filter_var(...VALIDATE_BOOL): "1", "true", "on", "yes" → true
    define('APP_DEBUG', filter_var($debugEnv, FILTER_VALIDATE_BOOL));
} else {
    /* Sunucu adındaki port kısmını at ("localhost:8080" → "localhost").
     * Komut satırında çalışırken HTTP_HOST hiç tanımlı olmaz; bu
     * durumda geliştirme ortamında sayılırız (CLI bir ziyaretçi değildir). */
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = explode(':', $host)[0];

    define('APP_DEBUG', $host === ''
        || in_array($host, ['localhost', '127.0.0.1', '::1', 'localhost.localdomain'], true)
        || str_ends_with($host, '.test')
        || str_ends_with($host, '.local'));
}

// Görev başlığı sınırları (veritabanındaki VARCHAR(160) ile uyumlu).
define('TASK_TITLE_MIN', 3);
define('TASK_TITLE_MAX', 160);

// Açıklama sınırı. TEXT sütunu 65.535 bayt alır; 2000 karakter
// hem fazlasıyla yeterli hem de arayüzde okunabilir bir üst sınır.
define('TASK_DESC_MAX', 2000);

/**
 * Öncelik seçenekleri: veritabanı değeri => ekranda görünen etiket.
 *
 * Bu dizi TEK DOĞRULUK KAYNAĞIDIR: form açılır listesi, doğrulama
 * ve kart rozetleri hep buradan beslenir. ENUM'a yeni bir değer
 * eklerseniz burayı da güncellemeniz yeterlidir.
 */
define('TASK_PRIORITIES', [
    'dusuk'  => 'Düşük',
    'orta'   => 'Orta',
    'yuksek' => 'Yüksek',
]);

/**
 * Arama kutusuna en fazla kaç karakter kabul edilir?
 *
 * Sınır olmasaydı, megabaytlarca metinden oluşan bir arama terimi
 * her satırda tek tek taranan devasa bir LIKE desenine dönüşürdü.
 * Görev başlığı zaten en fazla TASK_TITLE_MAX karakterdir; ondan
 * uzun bir aramanın hiçbir zaman sonucu olamaz.
 */
define('SEARCH_MAX', TASK_TITLE_MAX);

/**
 * Tek bir sütuna en fazla kaç kart konabilir?
 *
 * Sürükle-bırak isteği, sütundaki TÜM kart sıralamasını gönderir.
 * Sınırsız kabul etmek, kötü niyetli birinin devasa bir dizi
 * göndererek sunucuyu yormasına açık kapı bırakır.
 */
define('MAX_TASKS_PER_COLUMN', 500);

// Hata gösterimini APP_DEBUG ayarına göre aç/kapat.
error_reporting(APP_DEBUG ? E_ALL : 0);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

/* ---------------------------------------------------------------------
 *  4) VERİTABANI BAĞLANTISI (PDO)
 * ------------------------------------------------------------------ */
try {
    $db = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [
            // Sorgu hata verdiğinde istisna fırlat; sessizce yutma.
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            // Sonuçları $row['title'] şeklinde isimle döndür.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            /* Sorgu gerçekten MySQL tarafından hazırlanır.
             * DİKKAT: Bu ayar açıkken aynı isimli yer tutucu (:deger)
             * bir sorguda İKİ KEZ kullanılamaz. Farklı isimler verin. */
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    echo APP_DEBUG
        ? 'Veritabanı bağlantı hatası: ' . $e->getMessage()
        : 'Veritabanına bağlanılamadı. Lütfen daha sonra tekrar deneyin.';

    exit;
}
