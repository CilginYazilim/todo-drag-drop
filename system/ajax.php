<?php
/**
 * =====================================================================
 *  AJAX UÇ NOKTASI (Endpoint) – Tüm işlemlerin merkezi
 *  cilginyazilim.com – Sürükle-Bırak Görev Panosu
 * ---------------------------------------------------------------------
 *  Bu dosya HTML üretmez; SADECE JSON döndürür.
 *
 *  "action" parametresi hangi işlemin yapılacağını belirler:
 *  ---------------------------------------------------------------
 *    action=board   → Panonun tamamı (sütunlar + görevler)
 *    action=add     → Yeni görev ekle
 *    action=edit    → Mevcut görevi güncelle
 *    action=fetch   → Tek görevi getir (düzenleme formu için)
 *    action=delete  → Görevi sil
 *    action=move    → SÜRÜKLE-BIRAK: kartın sütununu ve sırasını yaz
 *  ---------------------------------------------------------------
 *
 *  NEDEN TEK DOSYA?
 *  Her işlem için ayrı dosya açmak yerine tek giriş noktası
 *  kullanmak, güvenlik kontrollerini (CSRF, POST zorunluluğu, hata
 *  yakalama) TEK YERDE toplamayı sağlar. Böylece bir kontrolü
 *  yanlışlıkla bir dosyada unutma riski kalmaz.
 * =====================================================================
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/function.php';

/* ---------------------------------------------------------------------
 *  GÜVENLİK KONTROLÜ: Sadece POST kabul edilir.
 * ---------------------------------------------------------------------
 *  Veri değiştiren işlemler asla GET ile yapılmamalıdır. Aksi halde
 *  <img src="ajax.php?action=delete&id=5"> gibi basit bir etiket bile
 *  kayıt silebilirdi.
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Yalnızca POST istekleri kabul edilir.', 405);
}

$action = isset($_POST['action']) ? strtolower(trim((string) $_POST['action'])) : 'board';

/* try/catch: İçeride NEREDE hata olursa olsun buraya düşer. Böylece
 * kullanıcı çirkin bir PHP hata sayfası yerine düzgün bir JSON
 * mesajı görür ve JavaScript bunu işleyebilir. */
try {
    switch ($action) {
        case 'add':
        case 'edit':
            handle_save($db, $action);
            break;

        case 'fetch':
            handle_fetch($db);
            break;

        case 'delete':
            handle_delete($db);
            break;

        case 'move':
            handle_move($db);
            break;

        case 'board':
        default:
            handle_board($db);
            break;
    }
} catch (PDOException $e) {
    error_log('[TODO] Veritabani hatasi: ' . $e->getMessage());

    // GÜVENLİK: Canlı ortamda hata detayı kullanıcıya GÖSTERİLMEZ.
    // Tablo/sütun isimleri saldırgana bilgi verir.
    json_error(
        APP_DEBUG ? 'Veritabanı hatası: ' . $e->getMessage()
                  : 'Beklenmeyen bir veritabanı hatası oluştu.',
        500
    );
} catch (Throwable $e) {
    error_log('[TODO] Hata: ' . $e->getMessage());

    json_error(
        APP_DEBUG ? 'Hata: ' . $e->getMessage() : 'Beklenmeyen bir hata oluştu.',
        500
    );
}


/* =====================================================================
 *  1) PANOYU GETİR
 * ================================================================== */
function handle_board(PDO $db): void
{
    require_csrf();

    $search = trim((string) ($_POST['search'] ?? ''));

    /* Bozuk UTF-8 gelirse desendeki /u yüzünden arama sessizce
     * boşa düşerdi; kullanıcı "hiç sonuç yok" sanır. Baştan reddetmek
     * daha dürüst. */
    if (!is_valid_utf8($search)) {
        json_error('Arama terimi geçersiz karakterler içeriyor.', 422);
    }

    // Fazlasını reddetmek yerine kırpıyoruz: kullanıcı yanlışlıkla
    // uzun bir metin yapıştırdığında hata görmesindense, anlamlı
    // kısmıyla arama yapılması daha kullanışlıdır.
    $search  = mb_substr($search, 0, SEARCH_MAX, 'UTF-8');
    $columns = fetch_board($db, $search);

    // Toplam kart sayısı: üstteki sayaç için.
    $total = 0;

    foreach ($columns as $column) {
        $total += $column['count'];
    }

    json_response([
        'success' => true,
        'columns' => $columns,
        'total'   => $total,
        'search'  => $search,
    ]);
}


/* =====================================================================
 *  2) GÖREV EKLEME / GÜNCELLEME
 * ================================================================== */
function handle_save(PDO $db, string $action): void
{
    require_csrf();

    $errors = [];

    /* Her doğrulayıcı [temizlenmiş değer, hata] çifti döndürür.
     * Hataları tek tek toplayıp hepsini birden gösteriyoruz;
     * kullanıcıyı "bir hatayı düzelt, sonrakini gör" döngüsüne
     * sokmak kötü bir deneyimdir. */
    [$title, $titleError]       = validate_task_title($_POST['title'] ?? '');
    [$description, $descError]  = validate_task_description($_POST['description'] ?? '');
    [$priority, $priorityError] = validate_priority($_POST['priority'] ?? '');
    [$dueDate, $dueError]       = validate_due_date($_POST['due_date'] ?? '');

    foreach ([
        'title'       => $titleError,
        'description' => $descError,
        'priority'    => $priorityError,
        'due_date'    => $dueError,
    ] as $field => $message) {
        if ($message !== null) {
            $errors[$field] = $message;
        }
    }

    $isEdit = ($action === 'edit');

    /* --- DÜZENLEME: kayıt var mı? --------------------------------- */
    $current = null;

    if ($isEdit) {
        /* filter_input(...FILTER_VALIDATE_INT): Gelen değerin gerçekten
         * tam sayı olduğunu doğrular. "5abc" veya "1 OR 1=1" gibi
         * değerler false döner ve işlem durur. */
        $id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($id === false || $id === null) {
            json_error('Geçersiz görev numarası.');
        }

        $current = find_task($db, $id);

        if ($current === null) {
            json_error('Güncellenecek görev bulunamadı.', 404);
        }
    }

    /* --- HEDEF SÜTUN ---------------------------------------------- */
    $columnId = filter_input(INPUT_POST, 'column_id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($columnId === false || $columnId === null) {
        // Düzenlemede sütun gönderilmemişse mevcut sütununda kalsın.
        $columnId = $isEdit ? (int) $current['column_id'] : 0;
    }

    if ($columnId <= 0 || !column_exists($db, $columnId)) {
        $errors['column_id'] = 'Geçerli bir sütun seçiniz.';
    }

    if ($errors !== []) {
        // 422 = "Unprocessable Entity" (doğrulama hatası).
        // JavaScript bu kodu görünce hataları alanların altına yazar.
        json_error('Lütfen formdaki hataları düzeltin.', 422, ['errors' => $errors]);
    }

    /* --- GÜNCELLEME ------------------------------------------------ */
    if ($isEdit) {
        /* SÜTUN DEĞİŞTİYSE KART SONA EKLENİR.
         * Formdan sütun değiştirmek, sürükle-bırakın klavyeyle
         * yapılabilen karşılığıdır; kartın yeni sütunda nereye
         * gideceği belirsiz olduğu için en sona konur. */
        $sortOrder = (int) $current['sort_order'];

        if ($columnId !== (int) $current['column_id']) {
            $sortOrder = next_sort_order($db, $columnId);
        }

        $stmt = $db->prepare(
            'UPDATE tasks
                SET column_id = :column_id, title = :title, description = :description,
                    priority = :priority, due_date = :due_date, sort_order = :sort_order
              WHERE id = :id'
        );

        // Değerler ayrı gönderilir, sorguya yapıştırılmaz →
        // SQL Injection imkânsız.
        $stmt->execute([
            ':column_id'   => $columnId,
            ':title'       => $title,
            ':description' => $description,
            ':priority'    => $priority,
            ':due_date'    => $dueDate,
            ':sort_order'  => $sortOrder,
            ':id'          => $current['id'],
        ]);

        // Eski sütunda boşluk kaldıysa numaraları sıkıştır.
        if ($columnId !== (int) $current['column_id']) {
            resequence_column($db, (int) $current['column_id']);
        }

        json_success('Görev güncellendi.', ['id' => (int) $current['id']]);
    }

    /* --- YENİ GÖREV ------------------------------------------------ */
    $stmt = $db->prepare(
        'INSERT INTO tasks (column_id, title, description, priority, due_date, sort_order)
         VALUES (:column_id, :title, :description, :priority, :due_date, :sort_order)'
    );

    $stmt->execute([
        ':column_id'   => $columnId,
        ':title'       => $title,
        ':description' => $description,
        ':priority'    => $priority,
        ':due_date'    => $dueDate,
        // Yeni kart her zaman sütunun sonuna eklenir.
        ':sort_order'  => next_sort_order($db, $columnId),
    ]);

    // lastInsertId(): Az önce eklenen kaydın otomatik ID'sini verir.
    json_success('Görev eklendi.', ['id' => (int) $db->lastInsertId()]);
}


/* =====================================================================
 *  3) TEK GÖREV GETİRME
 * ================================================================== */
function handle_fetch(PDO $db): void
{
    require_csrf();

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($id === false || $id === null) {
        json_error('Geçersiz görev numarası.');
    }

    $task = find_task($db, $id);

    if ($task === null) {
        json_error('Görev bulunamadı.', 404);
    }

    /* NEDEN HTML DEĞİL DE HAM VERİ DÖNÜYORUZ?
     * Sunucudan hazır HTML göndermek yerine ham veri gönderip ekranı
     * JavaScript'in doldurması daha güvenlidir: JavaScript .text()
     * kullandığında içerik asla HTML olarak yorumlanmaz, yani XSS
     * riski ortadan kalkar. */
    json_response(array_merge(
        ['success' => true],
        present_task($task)
    ));
}


/* =====================================================================
 *  4) GÖREV SİLME
 * ================================================================== */
function handle_delete(PDO $db): void
{
    require_csrf();

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($id === false || $id === null) {
        json_error('Geçersiz görev numarası.');
    }

    // Silmeden ÖNCE sütununu öğren; sonra öğrenemeyiz.
    $task = find_task($db, $id);

    if ($task === null) {
        json_error('Silinecek görev bulunamadı.', 404);
    }

    $stmt = $db->prepare('DELETE FROM tasks WHERE id = :id');
    $stmt->execute([':id' => $id]);

    // Kart gitti; geride kalan boşluğu kapat.
    resequence_column($db, (int) $task['column_id']);

    json_success('Görev silindi.', ['id' => $id]);
}


/* =====================================================================
 *  5) SÜRÜKLE-BIRAK: KARTI TAŞI
 * =====================================================================
 *  BU FONKSİYON UYGULAMANIN KALBİDİR.
 *
 *  İstemci şunları gönderir:
 *    task_id   → taşınan kart
 *    column_id → kartın BIRAKILDIĞI sütun
 *    order[]   → o sütundaki TÜM kartların yeni sırası (id listesi)
 *
 *  NEDEN "kartı 3. sıraya al" demek yerine TÜM SIRA gönderiliyor?
 *  Çünkü tek bir kartın konumunu bildirmek, sunucunun geri kalan
 *  kartları nasıl kaydıracağını tahmin etmesini gerektirir. Tarayıcı
 *  zaten doğru sırayı ekranda tutuyor; onu olduğu gibi göndermek
 *  hem daha basit hem de ekranla veritabanının ayrışmasını imkânsız
 *  kılar. Liste birkaç yüz sayıdan ibarettir, maliyeti önemsizdir.
 *
 *  TAMAMI TEK TRANSACTION İÇİNDEDİR: yarısı yazılmış bir sıralama,
 *  hiç yazılmamış olandan kötüdür.
 * ------------------------------------------------------------------ */
function handle_move(PDO $db): void
{
    require_csrf();

    $taskId = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    $columnId = filter_input(INPUT_POST, 'column_id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($taskId === false || $taskId === null || $columnId === false || $columnId === null) {
        json_error('Geçersiz taşıma isteği.');
    }

    $task = find_task($db, $taskId);

    if ($task === null) {
        json_error('Taşınacak görev bulunamadı.', 404);
    }

    if (!column_exists($db, $columnId)) {
        json_error('Hedef sütun bulunamadı.', 404);
    }

    /* --- SIRA LİSTESİNİ TEMİZLE -----------------------------------
     * Gelen dizi kullanıcı girdisidir; tamamen güvenilmezdir.
     * Dört aşamadan geçiriyoruz:
     *   1. Her elemanı tam sayıya çevir, geçersizleri at
     *   2. Tekrar edenleri temizle (aynı kart iki kez gelemez)
     *   3. Üst sınırı aşarsa reddet
     *   4. YABANCI id'leri ele (aşağıdaki uzun nota bakın)
     * -------------------------------------------------------------- */
    $rawOrder = $_POST['order'] ?? [];

    if (!is_array($rawOrder)) {
        json_error('Geçersiz sıralama verisi.');
    }

    /* Üst sınır kontrolü HAM dizide yapılır. Önce temizleyip sonra
     * saysaydık, saldırgan 1 milyon geçersiz eleman gönderdiğinde
     * döngü yine de 1 milyon kez dönerdi; sınır ancak iş bittikten
     * sonra devreye girerdi. Sayacağımız şey, işlemeyi kabul
     * ettiğimiz şeydir. */
    if (count($rawOrder) > MAX_TASKS_PER_COLUMN) {
        json_error('Bir sütunda en fazla ' . MAX_TASKS_PER_COLUMN . ' görev bulunabilir.', 422);
    }

    /* --- YETKİ SINIRI: Hangi kartlar bu istekle taşınabilir? -------
     *
     * BU KONTROL OLMADAN NE OLUR?
     * Aşağıdaki döngü, listedeki HER id için
     * "UPDATE tasks SET column_id = :hedef" çalıştırır. Yani listeye
     * panonun BAŞKA bir sütunundaki kartların id'leri yazılırsa, o
     * kartlar da hedef sütuna sürüklenmiş gibi yerinden oynar.
     * Kullanıcı hiçbir şey yapmadan kartlarının yer değiştirdiğini
     * görür. (Tarayıcı bunu asla göndermez; ama uç nokta tarayıcıya
     * değil, İSTEĞE bakar.)
     *
     * KURAL: Meşru bir bırakma işleminde hedef sütunun yeni listesi
     * yalnızca şunlardan oluşabilir:
     *   - hedef sütunda ZATEN bulunan kartlar
     *   - sürüklenerek getirilen KARTIN KENDİSİ
     * Bu kümenin dışındaki her id sessizce atılır.
     *
     * Neden hata döndürmüyoruz? Kullanıcının panosu başka bir
     * sekmede değişmiş olabilir; bu durumda liste masumca eskimiştir.
     * İsteği tümden reddetmek yerine bilinen kartları doğru sıraya
     * dizmek, kullanıcıyı daha az şaşırtır. İstemci zaten her
     * işlemden sonra panoyu tazeler.
     * -------------------------------------------------------------- */
    $allowedIds   = column_task_ids($db, $columnId);
    $allowedIds[] = $taskId;

    $order = [];

    foreach ($rawOrder as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id !== false
            && in_array($id, $allowedIds, true)
            && !in_array($id, $order, true)) {

            $order[] = $id;
        }
    }

    /* Taşınan kart listede yoksa istemci ile sunucu farklı şeyler
     * anlıyor demektir. Sessizce sona eklemek yerine listeye dahil
     * ediyoruz; kartın kaybolması en kötü sonuçtur. */
    if (!in_array($taskId, $order, true)) {
        $order[] = $taskId;
    }

    $sourceColumnId = (int) $task['column_id'];

    $db->beginTransaction();

    try {
        /* --- HEDEF SÜTUNU YENİDEN NUMARALA -------------------------
         * Tek sorguyu hazırlayıp döngüde tekrar tekrar çalıştırıyoruz.
         * prepare() bir kez, execute() N kez: MySQL sorguyu yalnızca
         * bir defa çözümler, bu da döngü içinde prepare çağırmaktan
         * belirgin biçimde hızlıdır. */
        $stmt = $db->prepare(
            'UPDATE tasks SET column_id = :column_id, sort_order = :sort_order WHERE id = :id'
        );

        foreach ($order as $index => $id) {
            $stmt->execute([
                ':column_id'  => $columnId,
                ':sort_order' => $index,
                ':id'         => $id,
            ]);
        }

        /* --- KAYNAK SÜTUNDAKİ BOŞLUĞU KAPAT ------------------------
         * Kart başka bir sütuna gittiyse, eski sütunda 0,1,3,4 gibi
         * atlamalı numaralar kalır. Bu, ekranda bir sorun yaratmaz
         * (sıralama yine doğrudur) ama numaraları temiz tutmak,
         * ileride "araya ekle" gibi işlemleri kolaylaştırır. */
        if ($sourceColumnId !== $columnId) {
            resequence_column($db, $sourceColumnId);
        }

        $db->commit();
    } catch (Throwable $e) {
        // Hata hâlinde YARIM İŞ BIRAKMA: her şeyi geri al.
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $e; // Üstteki genel catch bloğu JSON hatasına çevirir.
    }

    json_success('Görev taşındı.', [
        'id'        => $taskId,
        'column_id' => $columnId,
    ]);
}


/**
 * Bir sütundaki kartların sıra numaralarını 0'dan başlayarak
 * boşluksuz hâle getirir.
 *
 * Kart silindiğinde veya başka sütuna taşındığında numaralarda
 * boşluk oluşur (0,1,3,4). Sıralama bozulmaz ama numaralar
 * anlamsızlaşır; bu fonksiyon onları sıkıştırır.
 *
 * DİKKAT: Bu fonksiyon transaction AÇMAZ. Çağıran taraf zaten bir
 * transaction içindeyse onu bölmemesi, değilse tek tek çalışması
 * gerekir. Bu yüzden kararı çağırana bırakıyoruz.
 */
function resequence_column(PDO $db, int $columnId): void
{
    $update = $db->prepare('UPDATE tasks SET sort_order = :sort_order WHERE id = :id');

    foreach (column_task_ids($db, $columnId) as $index => $id) {
        $update->execute([':sort_order' => $index, ':id' => $id]);
    }
}
