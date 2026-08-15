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
 *  getenv('DB_HOST') ?: '127.0.0.1'
 *      → Sunucuda DB_HOST ortam değişkeni tanımlıysa onu kullan,
 *        tanımlı değilse (veya boşsa) '127.0.0.1' kullan.
 *
 *  Şifreleri koda yazıp GitHub'a yüklemek en sık yapılan güvenlik
 *  hatasıdır. Ortam değişkeni kullanırsanız aynı kod, farklı
 *  sunucularda farklı şifrelerle çalışır ve şifre repoda görünmez.
 * ------------------------------------------------------------------ */
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'cy_todo');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '');

// utf8mb4: Türkçe karakterler ve emoji dahil tüm Unicode'u destekler.
define('DB_CHARSET', 'utf8mb4');

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
$debugEnv = getenv('APP_DEBUG');

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
