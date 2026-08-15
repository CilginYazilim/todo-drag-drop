<?php
/**
 * =====================================================================
 *  YARDIMCI FONKSİYONLAR
 *  cilginyazilim.com – Sürükle-Bırak Görev Panosu
 * ---------------------------------------------------------------------
 *  Burada tekrar tekrar kullanılan küçük işler toplanır:
 *  JSON yanıt üretme, CSRF, doğrulama, tarih biçimleme, veri erişimi.
 *
 *  TASARIM KARARI: Bu dosya config.php'yi DAHİL ETMEZ.
 *  Veritabanına ihtiyaç duyan fonksiyonlar PDO nesnesini PARAMETRE
 *  olarak alır: fetch_board($db) gibi. Böylece her çağrıda yeni
 *  bağlantı açılmaz ve fonksiyonlar test edilebilir kalır.
 *  (Bu yaklaşımın adı: "Dependency Injection")
 * =====================================================================
 */

declare(strict_types=1);


/* =====================================================================
 *  BÖLÜM 1 – ÇIKTI VE YANIT
 * ================================================================== */

/**
 * Metni HTML'e güvenle basmak için kaçışlar (XSS koruması).
 *
 * Kullanıcı görev başlığına <script>alert(1)</script> yazarsa ve biz
 * bunu ekrana olduğu gibi basarsak, tarayıcı bunu KOD olarak
 * çalıştırır. htmlspecialchars() bu karakterleri zararsız hale getirir.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Tarayıcıya güvenlik başlıklarını gönderir.
 *
 * Bu başlıklar sunucudaki bir açığı kapatmaz; TARAYICIYA "bu sayfada
 * şunlara izin verme" der. Yani bir hata yapıldığında zararın
 * büyümesini engelleyen ikinci savunma hattıdır.
 *
 * ÇIKTIDAN ÖNCE çağrılmalıdır: başlıklar gövdeden önce gider,
 * tek bir boşluk bile basıldıktan sonra eklenemezler.
 */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    /* Tarayıcı, dosyanın TÜRÜNÜ tahmin etmeye çalışmasın; ne
     * dediysek o olsun. Tahmin açık olduğunda, metin olarak sunulan
     * bir içerik tarayıcıda script sanılıp çalıştırılabilir. */
    header('X-Content-Type-Options: nosniff');

    /* Sayfa başka bir sitenin <iframe>'ine konulamaz. Bunu
     * engellemezsek saldırgan sayfamızı görünmez bir çerçeveye alıp
     * üstüne kendi düğmelerini koyabilir; kullanıcı "Sil" düğmemize
     * bastığını bilmeden basar. (Clickjacking) */
    header('X-Frame-Options: DENY');

    // Başka siteye giderken hangi sayfadan geldiğimizi söyleme.
    header('Referrer-Policy: no-referrer');

    /* İÇERİK GÜVENLİK POLİTİKASI (CSP)
     * -------------------------------------------------------------
     * "Kaynaklar yalnızca kendi sunucumdan gelebilir" kuralı. XSS'e
     * karşı en güçlü tarayıcı savunmasıdır: saldırgan sayfaya script
     * enjekte etmeyi başarsa bile, harici bir adrese veri gönderemez.
     *
     * 'unsafe-inline' NEDEN VAR?
     * Bu sayfada iki satır gömülü script (CyBoard.init) ve jQuery'nin
     * eleman üzerine yazdığı stil öznitelikleri var. Bunlar olmadan
     * sayfa çalışmaz. Gerçek anlamda sıkı bir politika için gömülü
     * kodun ayrı bir .js dosyasına taşınması ve stiller için nonce
     * kullanılması gerekir — bu örnekte okunabilirlik tercih edildi.
     * Bilinçli bir ödün olduğu için burada açıkça yazıyoruz.
     *
     * frame-ancestors: X-Frame-Options'ın modern karşılığı; ikisini
     * birden göndermek eski ve yeni tarayıcıları birlikte kapsar.
     */
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
}

/**
 * JSON yanıtı gönderir ve script'i sonlandırır.
 *
 * @param array<string,mixed> $payload JSON'a çevrilecek dizi
 * @param int                 $status  HTTP durum kodu
 */
function json_response(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        // Aynı başlıkları burada TEKRAR yazmak yerine tek kaynaktan
        // alıyoruz; ileride bir başlık eklendiğinde JSON uç noktası
        // geride kalmaz.
        send_security_headers();
    }

    // JSON_UNESCAPED_UNICODE: Türkçe karakterler ç gibi
    // kodlanmasın, doğrudan "ç" olarak yazılsın.
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Standart BAŞARI yanıtı. */
function json_success(string $description, array $extra = []): void
{
    json_response(array_merge([
        'success'     => true,
        'type'        => 'success',
        'description' => $description,
    ], $extra));
}

/**
 * Standart HATA yanıtı.
 *
 * Kullanılan HTTP kodları:
 *   400 → Geçersiz istek      404 → Kayıt bulunamadı
 *   403 → CSRF geçersiz       422 → Doğrulama hatası
 *   405 → Yanlış yöntem       500 → Sunucu hatası
 *
 * NEDEN 419 DEĞİL DE 403?
 * Bazı çerçeveler (Laravel gibi) CSRF hatası için 419 kullanır. Ancak
 * 419 IANA'ya KAYITLI BİR KOD DEĞİLDİR: Apache onu tanımadığı için
 * yanıt satırını sessizce "500 Internal Server Error" olarak yeniden
 * yazar. Sonuç: reddedilmiş bir istek, sunucu çökmüş gibi görünür;
 * günlükler ve izleme araçları yanlış alarm verir.
 * 403 Forbidden standarttır, her sunucu ve vekil sunucu tarafından
 * olduğu gibi geçirilir ve anlamı da tam olarak budur:
 * "İsteğini anladım, ama yapmana izin vermiyorum."
 */
function json_error(string $description, int $status = 400, array $extra = []): void
{
    json_response(array_merge([
        'success'     => false,
        'type'        => 'danger',
        'description' => $description,
    ], $extra), $status);
}


/* =====================================================================
 *  BÖLÜM 2 – CSRF KORUMASI
 * =====================================================================
 *  CSRF (Cross-Site Request Forgery): Siz sitemizi kullanırken başka
 *  bir kötü niyetli site, tarayıcınız üzerinden bizim ajax.php'mize
 *  gizlice istek gönderir. Tarayıcı çerezleri otomatik eklediği için
 *  sunucu bunu SİZİN yaptığınızı sanır.
 *
 *  ÇÖZÜM: Her oturuma özel, tahmin edilemez bir anahtar. Başka bir
 *  site bu anahtarı okuyamaz (same-origin policy), dolayısıyla
 *  geçerli istek üretemez.
 * ================================================================== */

/** Oturuma bağlı CSRF anahtarını döndürür (yoksa üretir). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        // random_bytes(): Kriptografik olarak güvenli rastgelelik.
        // rand()/mt_rand() KULLANMAYIN, tahmin edilebilirler.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Gelen isteğin CSRF anahtarını doğrular; geçersizse 419 ile durur.
 *
 * hash_equals() NEDEN? Normal "===" ilk farklı karakterde durur ve
 * saldırgan yanıt SÜRESİNİ ölçerek anahtarı karakter karakter tahmin
 * edebilir ("timing attack"). hash_equals() her zaman aynı sürede
 * çalışarak bunu engeller.
 */
function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($token) || $token === ''
        || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $token)) {

        // 403 → bkz. json_error() üzerindeki "NEDEN 419 DEĞİL DE 403?" notu.
        json_error('Oturum doğrulaması başarısız. Lütfen sayfayı yenileyin.', 403);
    }
}


/* =====================================================================
 *  BÖLÜM 3 – DOĞRULAMA
 * =====================================================================
 *  ALTIN KURAL: İstemci (JavaScript) tarafındaki doğrulama sadece
 *  KULLANICI DENEYİMİ içindir. Kötü niyetli biri tarayıcıyı hiç
 *  kullanmadan doğrudan sunucuya istek atabilir. Bu yüzden her
 *  kontrol SUNUCUDA TEKRAR yapılmalıdır.
 * ================================================================== */

/**
 * Metin gerçekten geçerli UTF-8 mi?
 *
 * NEDEN GEREKLİ?
 * Bu projedeki desenlerin sonunda /u (unicode) değiştiricisi vardır.
 * preg_replace, GEÇERSİZ UTF-8 baytları görünce sessizce NULL döner.
 * Bu NULL'ı boş metne çevirdiğimizde kullanıcı "başlık boş
 * bırakılamaz" gibi tamamen alakasız bir hata görür ve ne yaptığını
 * anlamaz.
 *
 * Tarayıcı, UTF-8 olarak sunulan bir sayfadan her zaman UTF-8
 * gönderir; yani bu durum normal kullanımda oluşmaz. Ancak eski bir
 * istemci, elle yazılmış bir script veya kasıtlı bir istek bozuk
 * bayt gönderebilir. Sessiz ve yanıltıcı bir hata yerine, ne olduğunu
 * söyleyen bir mesaj vermek doğrusudur.
 */
function is_valid_utf8(?string $value): bool
{
    return mb_check_encoding((string) $value, 'UTF-8');
}

/**
 * Görev başlığını temizler ve doğrular.
 *
 * @return array{0:string,1:?string} [temizlenmiş değer, hata|null]
 */
function validate_task_title(?string $value): array
{
    if (!is_valid_utf8($value)) {
        return ['', 'Görev başlığı geçersiz karakterler içeriyor (metin UTF-8 değil).'];
    }

    // Aradaki çoklu boşlukları teke indir; baştaki/sondakini sil.
    // Sondaki /u : Desenin UTF-8 (Türkçe karakterli) metinle çalışması için.
    $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

    if ($value === '') {
        return ['', 'Görev başlığı boş bırakılamaz.'];
    }

    // mb_strlen(): Çok baytlı karakterleri doğru sayar.
    // strlen("Çılgın") = 8 (yanlış), mb_strlen("Çılgın") = 6 (doğru)
    $length = mb_strlen($value, 'UTF-8');

    if ($length < TASK_TITLE_MIN) {
        return [$value, 'Görev başlığı en az ' . TASK_TITLE_MIN . ' karakter olmalıdır.'];
    }

    if ($length > TASK_TITLE_MAX) {
        return [$value, 'Görev başlığı en fazla ' . TASK_TITLE_MAX . ' karakter olabilir.'];
    }

    return [$value, null];
}

/**
 * Açıklamayı temizler ve doğrular. Boş bırakılabilir.
 *
 * @return array{0:?string,1:?string}
 */
function validate_task_description(?string $value): array
{
    if (!is_valid_utf8($value)) {
        return [null, 'Açıklama geçersiz karakterler içeriyor (metin UTF-8 değil).'];
    }

    // Satır sonları korunur (açıklama çok satırlı olabilir);
    // yalnızca baş/son boşluklar temizlenir.
    $value = trim((string) $value);

    if ($value === '') {
        // Boş açıklama '' yerine NULL kaydedilir: "değer yok" ile
        // "değer boş metin" veritabanında farklı şeylerdir.
        return [null, null];
    }

    if (mb_strlen($value, 'UTF-8') > TASK_DESC_MAX) {
        return [$value, 'Açıklama en fazla ' . TASK_DESC_MAX . ' karakter olabilir.'];
    }

    return [$value, null];
}

/**
 * Öncelik değerini doğrular.
 *
 * BEYAZ LİSTE (whitelist) yaklaşımı: "şu değerler yasak" demek
 * yerine "yalnızca şu değerler serbest" deriz. Listede olmayan her
 * şey reddedilir; yeni bir saldırı biçimi çıksa bile kural değişmez.
 *
 * @return array{0:string,1:?string}
 */
function validate_priority(?string $value): array
{
    $value = trim((string) $value);

    if ($value === '') {
        return ['orta', null]; // Belirtilmediyse varsayılan.
    }

    if (!array_key_exists($value, TASK_PRIORITIES)) {
        return ['orta', 'Geçersiz öncelik değeri.'];
    }

    return [$value, null];
}

/**
 * Son teslim tarihini doğrular. Boş bırakılabilir.
 *
 * Kabul edilen biçimler: 2026-08-22 (HTML date girdisi) ve
 * 22.08.2026 (elle yazım).
 *
 * NEDEN strtotime() KULLANMIYORUZ?
 * strtotime('04.03.2026') İngilizce yorumla AY/GÜN sırasını
 * karıştırabilir. createFromFormat ile biçimi açıkça belirtmek
 * bu belirsizliği yok eder.
 *
 * @return array{0:?string,1:?string}
 */
function validate_due_date(?string $value): array
{
    $value = trim((string) $value);

    if ($value === '') {
        return [null, null];
    }

    foreach (['Y-m-d', 'd.m.Y', 'd/m/Y'] as $format) {
        // Baştaki '!' : Belirtilmeyen alanları (saat, dakika) bugünün
        // değerleriyle değil SIFIRLA doldurur.
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);

        /* İki katmanlı kontrol: createFromFormat, '31.02.2026' gibi
         * OLMAYAN bir tarihi sessizce 3 Mart'a taşır. Geri
         * biçimlendirip girdiyle karşılaştırarak bunu yakalarız. */
        if ($date !== false && $date->format($format) === $value) {
            return [$date->format('Y-m-d'), null];
        }
    }

    return [null, 'Tarih GG.AA.YYYY biçiminde olmalıdır (örn. 22.08.2026).'];
}

/**
 * Sütun numarasının gerçekten var olduğunu doğrular.
 *
 * Bu kontrol ŞART: istemciden gelen column_id doğrudan INSERT'e
 * gitseydi, olmayan bir sütun numarası yabancı anahtar hatası
 * fırlatır ve kullanıcı anlaşılmaz bir 500 hatası görürdü.
 */
function column_exists(PDO $db, int $columnId): bool
{
    $stmt = $db->prepare('SELECT 1 FROM task_columns WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $columnId]);

    return $stmt->fetchColumn() !== false;
}


/* =====================================================================
 *  BÖLÜM 4 – VERİ ERİŞİMİ
 * ================================================================== */

/**
 * Panonun tamamını (sütunlar + görevler) tek seferde getirir.
 *
 * NEDEN İKİ SORGU, SÜTUN BAŞINA BİR SORGU DEĞİL?
 * Sütun sayısı kadar sorgu atmak ("N+1 sorgu problemi") 4 sütunda
 * fark ettirmez ama 20 sütunlu bir panoda sayfayı gözle görülür
 * biçimde yavaşlatır. Tüm görevleri TEK sorguda çekip PHP tarafında
 * sütunlara dağıtmak her zaman daha hızlıdır.
 *
 * @return array<int,array<string,mixed>> Sütunlar; her birinde 'tasks' dizisi
 */
function fetch_board(PDO $db, string $search = ''): array
{
    /* --- 1) Sütunlar --- */
    $columns = $db->query(
        'SELECT id, title, accent, sort_order, is_done
           FROM task_columns
          ORDER BY sort_order ASC, id ASC'
    )->fetchAll();

    /* --- 2) Görevler --- */
    $sql    = 'SELECT id, column_id, title, description, priority, due_date, sort_order
                 FROM tasks';
    $params = [];

    if ($search !== '') {
        /* NOT: PDO::ATTR_EMULATE_PREPARES kapalıyken aynı isimli yer
         * tutucu iki kez kullanılamaz; bu yüzden iki ayrı ad verdik. */
        $sql .= ' WHERE title LIKE :s_title OR description LIKE :s_desc';

        $pattern = '%' . escape_like($search) . '%';
        $params  = [':s_title' => $pattern, ':s_desc' => $pattern];
    }

    // Sıralama, panodaki kart sırasının ta kendisidir.
    $sql .= ' ORDER BY column_id ASC, sort_order ASC, id ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    /* --- 3) Görevleri sütunlara dağıt --- */
    $grouped = [];

    foreach ($stmt->fetchAll() as $task) {
        $grouped[(int) $task['column_id']][] = present_task($task);
    }

    foreach ($columns as &$column) {
        $column['id']      = (int) $column['id'];
        $column['is_done'] = (bool) $column['is_done'];
        $column['tasks']   = $grouped[$column['id']] ?? [];
        $column['count']   = count($column['tasks']);
    }
    unset($column); // Referansla döngü sonrası değişkeni serbest bırak.

    return $columns;
}

/**
 * Ham veritabanı satırını, arayüzün beklediği biçime çevirir.
 *
 * NEDEN AYRI BİR KATMAN?
 * "Bu görev gecikmiş mi?" sorusunun cevabı hem panoda hem de
 * ileride eklenecek bir raporda gerekir. Hesabı burada tek yerde
 * yapmak, iki ekranın farklı sonuç göstermesini imkânsız kılar.
 *
 * @return array<string,mixed>
 */
function present_task(array $task): array
{
    $due = $task['due_date'] !== null ? (string) $task['due_date'] : null;

    return [
        'id'          => (int) $task['id'],
        'column_id'   => (int) $task['column_id'],
        'title'       => (string) $task['title'],
        'description' => $task['description'] !== null ? (string) $task['description'] : '',
        'priority'    => (string) $task['priority'],
        'priority_label' => TASK_PRIORITIES[$task['priority']] ?? '',
        'due_date'    => $due,
        'due_label'   => format_day($due),
        'is_overdue'  => is_overdue($due),
    ];
}

/**
 * Son teslim tarihi geçmiş mi?
 *
 * BUGÜN teslim tarihi olan görev GECİKMİŞ SAYILMAZ; günün sonuna
 * kadar vakti vardır. Bu yüzden karşılaştırma "küçüktür" ile yapılır,
 * "küçük veya eşittir" ile değil.
 */
function is_overdue(?string $dueDate): bool
{
    if ($dueDate === null || $dueDate === '') {
        return false;
    }

    // Her iki tarafı da 'Y-m-d' metni olarak karşılaştırmak güvenlidir:
    // bu biçimde metin sıralaması ile tarih sıralaması aynıdır.
    return $dueDate < date('Y-m-d');
}

/** Tek bir görevi getirir. Bulunamazsa null döner. */
function find_task(PDO $db, int $id): ?array
{
    $stmt = $db->prepare(
        'SELECT id, column_id, title, description, priority, due_date, sort_order
           FROM tasks WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);

    $row = $stmt->fetch();

    // fetch() kayıt yoksa false döner; biz null'a çeviriyoruz.
    return $row ?: null;
}

/**
 * Bir sütundaki görev id'lerini panodaki sırasıyla döndürür.
 *
 * İKİ AYRI YERDE GEREKLİDİR, bu yüzden ortak fonksiyon:
 *   1. resequence_column() → numaraları 0,1,2… diye sıkıştırırken
 *   2. handle_move()       → gelen sıra listesini DOĞRULARKEN
 *
 * @return int[] Sıralı görev id'leri
 */
function column_task_ids(PDO $db, int $columnId): array
{
    $stmt = $db->prepare(
        'SELECT id FROM tasks WHERE column_id = :id ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([':id' => $columnId]);

    // fetchAll(PDO::FETCH_COLUMN): Sonucu doğrudan [1, 5, 9] gibi düz
    // bir diziye çevirir; iç içe dizilerle uğraşmaya gerek kalmaz.
    // array_map ile int'e çeviriyoruz: PDO sayıları metin döndürebilir
    // ve in_array(..., true) katı karşılaştırmasında '5' !== 5 olur.
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Bir sütunun sonuna eklenecek kartın sıra numarasını verir.
 *
 * COALESCE(MAX(...), -1) + 1 : Sütun boşsa MAX() NULL döner;
 * COALESCE bunu -1 yapar ve sonuç 0 olur. Bu küçük numara,
 * "sütun boş mu?" diye ayrı bir kontrol yazmayı gereksiz kılar.
 */
function next_sort_order(PDO $db, int $columnId): int
{
    $stmt = $db->prepare(
        'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tasks WHERE column_id = :id'
    );
    $stmt->execute([':id' => $columnId]);

    return (int) $stmt->fetchColumn();
}

/**
 * LIKE kalıbındaki joker karakterleri etkisizleştirir.
 *
 * SQL'de LIKE için: % = "sıfır veya daha fazla karakter",
 * _ = "tam olarak bir karakter". Kullanıcı arama kutusuna "%"
 * yazarsa TÜM kayıtlar dönerdi. Bu fonksiyon onları düz metne çevirir.
 * (Prepared statement SQL Injection'ı engeller ama joker
 *  karakterlerin ANLAMINI değiştirmez; bu ayrı bir konudur.)
 */
function escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
}


/* =====================================================================
 *  BÖLÜM 5 – BİÇİMLENDİRME
 * ================================================================== */

/**
 * 'YYYY-MM-DD' tarihini 'GG.AA.YYYY' yapar. Boşsa boş metin döner.
 */
function format_day(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

    // Tarih bozuksa uygulamayı çökertmek yerine ham değeri göster.
    return $date !== false ? $date->format('d.m.Y') : $value;
}
