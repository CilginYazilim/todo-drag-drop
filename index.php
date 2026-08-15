<?php
/**
 * =====================================================================
 *  ANA SAYFA (Sunum Katmanı)
 *  cilginyazilim.com – Sürükle-Bırak Görev Panosu
 * ---------------------------------------------------------------------
 *  Bu dosya SADECE arayüzü çizer ve JavaScript ile AJAX isteklerini
 *  yönetir. Veritabanı işlemleri burada YAPILMAZ; hepsi
 *  "system/ajax.php" dosyasındadır.
 *
 *  SAYFANIN AKIŞI:
 *    1. config.php  → oturumu başlatır, veritabanına bağlanır
 *    2. function.php→ yardımcı fonksiyonları yükler
 *    3. csrf_token()→ forma gömülecek güvenlik anahtarını üretir
 *    4. HTML iskeleti çizilir (pano BOŞ olarak)
 *    5. JavaScript panoyu ajax.php'den alıp doldurur
 *
 *  NEDEN PANO PHP İLE DEĞİL DE JAVASCRIPT İLE ÇİZİLİYOR?
 *  Kartlar sürüklendikçe, eklendikçe ve silindikçe pano sürekli
 *  yeniden çizilir. Aynı kart HTML'ini bir PHP'de bir JavaScript'te
 *  iki kez yazmak, ikisinin zamanla ayrışması demektir. Tek bir
 *  çizim yeri (JavaScript) bu riski ortadan kaldırır.
 * =====================================================================
 */

declare(strict_types=1);

require __DIR__ . '/system/config.php';
require __DIR__ . '/system/function.php';

// Tarayıcı savunmalarını aç (clickjacking, MIME tahmini, CSP).
// Aşağıdaki HTML'den ÖNCE çağrılması şarttır.
send_security_headers();

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Çılgın Yazılım - cilginyazilim.com">
    <meta name="description" content="PHP, MySQL ve saf JavaScript ile geliştirilmiş sürükle-bırak görev panosu (Kanban). Dokunmatik destekli, klavyeyle de kullanılabilir.">

    <!--
        CSRF anahtarını meta etiketine koyuyoruz.
        JavaScript bunu okuyup her AJAX isteğine ekleyecek.
    -->
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">

    <title>Sürükle-Bırak Görev Panosu | Çılgın Yazılım</title>

    <link rel="icon" type="image/png" href="assets/images/logo.png">

    <!--
        CSS YÜKLEME SIRASI ÖNEMLİDİR:
        1) bootstrap      → temel çatı
        2) cilginyazilim  → MARKA TASARIM KALIBI (Bootstrap'i ezer)
        3) style          → sadece bu sayfaya özel eklemeler
    -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/cilginyazilim.css">
    <!--
        ?v=<dosya değişim zamanı> : ÖNBELLEK KIRICI (cache buster).
        style.css her düzenlendiğinde tarayıcı, adresi DEĞİŞMEYEN bir
        dosyayı önbellekten okumaya devam edebilir. Sorgu dizesini
        dosyanın son değişim zamanına bağlamak, her gerçek değişiklikte
        adresi otomatik değiştirir ve tarayıcıyı yeni dosyayı indirmeye
        zorlar.
    -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>

<body class="cy-app">

    <div class="cy-topbar"></div>

    <div class="container-fluid cy-board-container py-4">

        <div class="cy-card">

            <!-- ---------- Kart Başlığı ---------- -->
            <div class="cy-card__header">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">

                    <a class="cy-brand" href="https://cilginyazilim.com" target="_blank" rel="noopener">
                        <span class="cy-brand__mark">
                            <img src="assets/images/logo.png" alt="Çılgın Yazılım logosu">
                        </span>
                        <div>
                            <h1 class="cy-brand__title">Görev Panosu</h1>
                            <p class="cy-brand__subtitle">
                                Sürükle-bırak &middot; Dokunmatik &middot; Klavye erişimi &middot; cilginyazilim.com
                            </p>
                        </div>
                    </a>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="cy-badge cy-badge--glass">
                            Toplam <strong id="total_tasks">0</strong> görev
                        </span>

                        <!--
                            type="search" : Mobil klavyede "ara" tuşu çıkar ve
                            tarayıcı alanın içine temizleme (×) düğmesi koyar.
                        -->
                        <input type="search" id="search_input" class="form-control cy-search"
                               placeholder="Görevlerde ara…" aria-label="Görevlerde ara"
                               maxlength="<?= (int) SEARCH_MAX ?>">

                        <button type="button" id="add_button" class="btn cy-btn cy-btn--onbrand">
                            <span aria-hidden="true">＋</span> Yeni Görev
                        </button>
                    </div>
                </div>
            </div>

            <!-- ---------- Kart Gövdesi: PANO ---------- -->
            <div class="cy-card__body cy-card__body--board">

                <!--
                    Panonun kendisi. Sütunlar JavaScript tarafından
                    buraya çizilir.

                    aria-live="polite" : Ekran okuyucular, kart taşındığında
                    veya eklendiğinde değişikliği kullanıcının işini
                    bölmeden okur.
                -->
                <div id="board" class="cy-board" aria-live="polite">
                    <div class="cy-board__loading">Pano yükleniyor…</div>
                </div>
            </div>

            <div class="cy-card__footer d-flex flex-wrap justify-content-between gap-2">
                <span>
                    Kartı sürükleyin ya da odaklanıp
                    <kbd>Ctrl</kbd> + <kbd>←</kbd> <kbd>→</kbd> <kbd>↑</kbd> <kbd>↓</kbd>
                    tuşlarını kullanın.
                </span>
                <span>PHP <?= e(PHP_VERSION) ?></span>
            </div>
        </div>

        <div class="cy-footer-note mt-4">
            <p class="mb-1">
                Bu açık kaynak örnek, <a href="https://cilginyazilim.com" target="_blank" rel="noopener">cilginyazilim.com</a>
                tarafından geliştirilmiştir. MIT lisanslıdır.
            </p>
            <p class="mb-0">
                Kaynak kod:
                <a href="https://github.com/CilginYazilim/todo-drag-drop"
                   target="_blank" rel="noopener">github.com/CilginYazilim/todo-drag-drop</a>
            </p>
        </div>
    </div>


    <!-- ================================================================
         MODAL 1 – GÖREV EKLEME / DÜZENLEME
         ----------------------------------------------------------------
         Tek bir modal hem ekleme hem düzenleme için kullanılır.
         Hangi işlemin yapılacağını gizli "action" alanı belirler.
         ================================================================ -->
    <div class="modal fade cy-modal" id="taskModal" tabindex="-1" aria-labelledby="taskModalLabel" aria-hidden="true">
        <!--
            modal-xl : Önceki modal-lg (800px) dar geliyordu; meta grubu
            (Sütun/Son Teslim/Öncelik) sıkışıp alt alta düşüyordu. modal-xl
            (1140px) bu üç alanın TEK satırda, nefes alarak durmasını sağlar.
        -->
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <!-- novalidate : Tarayıcının kendi uyarı balonlarını kapatır,
                 biz kendi hata mesajlarımızı gösteriyoruz. -->
            <form method="post" id="task_form" novalidate>
                <div class="modal-content">

                    <div class="modal-header">
                        <h2 class="modal-title h6 mb-0" id="taskModalLabel">Yeni Görev</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>

                    <div class="modal-body">
                        <div class="alert alert-danger d-none" id="form_alert" role="alert"></div>

                        <!--
                            İKİ SÜTUNLU DÜZEN (modal-xl ile birlikte)
                            ------------------------------------------
                            Solda SERBEST METİN (Başlık, Açıklama);
                            sağda SINIFLANDIRMA paneli (Sütun, Öncelik,
                            Son Teslim). Bu ayrım rastgele değil: Trello
                            kart detayı, Linear görev paneli gibi
                            araçların hepsi aynı ikiliyi kullanır —
                            "ne" sol tarafta büyük yazılır, "nerede/ne
                            zaman/ne kadar önemli" sağda kompakt bir
                            özet panelinde durur. Dar ekranda (bkz.
                            @media içindeki .cy-task-form-grid) panel
                            içeriğin ALTINA düşer, yan yana kalmaz.
                        -->
                        <div class="cy-task-form-grid">

                            <div class="cy-task-form-grid__main">
                                <div class="mb-3">
                                    <label for="title" class="form-label">Başlık <span class="text-danger">*</span></label>
                                    <!--
                                        cy-title-input: normal metin kutusundan daha
                                        büyük ve kalın — bu alan kartın ASIL adı,
                                        formdaki en önemli girdi. Trello/Linear gibi
                                        araçlarda görev başlığı hep böyle öne çıkar.
                                    -->
                                    <input type="text" name="title" id="title" class="form-control cy-title-input"
                                           placeholder="Örn: Ödeme sağlayıcısı entegrasyonu"
                                           maxlength="<?= (int) TASK_TITLE_MAX ?>" autocomplete="off">
                                    <!--
                                        Bootstrap kuralı: .invalid-feedback yalnızca
                                        kardeş elemanda .is-invalid varsa görünür.
                                        data-error-for özniteliğiyle JS hangi alanın
                                        hatası olduğunu bulur.
                                    -->
                                    <div class="invalid-feedback" data-error-for="title"></div>
                                </div>

                                <div class="mb-0">
                                    <label for="description" class="form-label">Açıklama</label>
                                    <!--
                                        rows="8": Sağdaki panel de (üç alan +
                                        etiketleri) benzer bir yükseklik
                                        kaplar; ikisini göz hizasında
                                        tutmak modalın alt kısmında
                                        dengesiz bir boşluk bırakmaz.
                                    -->
                                    <textarea name="description" id="description" class="form-control" rows="8"
                                              placeholder="İsteğe bağlı ayrıntılar…"
                                              maxlength="<?= (int) TASK_DESC_MAX ?>"></textarea>
                                    <div class="invalid-feedback" data-error-for="description"></div>
                                </div>
                            </div>

                            <!--
                                SAĞ PANEL: Sütun + Öncelik + Son Teslim.
                                Soluk zemin kartı, serbest metin
                                alanlarından görsel olarak ayrışır —
                                kullanıcı "bu taraf sınıflandırma
                                ayarları" diye tek bakışta anlar.
                            -->
                            <aside class="cy-task-form-grid__meta">
                                <div class="mb-3">
                                    <label for="column_id" class="form-label">Sütun</label>
                                    <!--
                                        Seçenekler JavaScript tarafından panodan
                                        doldurulur; her seçeneğin data-accent
                                        özniteliği, o sütunun panodaki rengini
                                        taşır (bkz. board.js updateColumnAccent()).
                                        cy-accent-select sınıfı bu rengi kutunun
                                        sol kenarında gösterir.
                                    -->
                                    <select name="column_id" id="column_id" class="form-select cy-accent-select"></select>
                                    <div class="invalid-feedback" data-error-for="column_id"></div>
                                </div>

                                <div class="mb-3">
                                    <span class="form-label d-block">Öncelik</span>
                                    <!--
                                        ÖNCELİK: <select> yerine RENKLİ SEÇİM
                                        KUTULARI (segmented control).
                                        ------------------------------------
                                        Üç radio input GÖRSEL OLARAK gizli, her
                                        biri kendi <label>'ına bağlı; tıklanan
                                        etiket ilgili radio'yu işaretler. Seçili
                                        olan, kartların sol kenarındaki öncelik
                                        rengiyle AYNI renkte dolgu alır — kullanıcı
                                        "hangi öncelik?" sorusunu bir <select>
                                        açmadan, tek bakışta cevaplar.

                                        Neden gerçek <select> değil: renk + metni
                                        aynı anda göstermek native <option>
                                        içinde mümkün değildir; tarayıcı
                                        <option>'ların arka planını her zaman
                                        ezer.

                                        id="priority" BİLEREK grup kapsayıcısında:
                                        JavaScript'teki showErrors() bu id'ye
                                        is-invalid ekler, kardeşi olan
                                        .invalid-feedback görünür olur — üç ayrı
                                        radio yerine TEK hata mesajı yeterlidir.

                                        cy-priority-picker--stacked: dar yan
                                        panelde üç pil yan yana sıkışmasın diye
                                        DİKEY dizilir (bkz. style.css).
                                    -->
                                    <div id="priority" class="cy-priority-picker cy-priority-picker--stacked"
                                         role="radiogroup" aria-label="Öncelik">
                                        <?php
                                        /* Seçenekler PHP'deki TEK tanımdan üretilir.
                                         * Elle yazılsaydı, ENUM'a yeni bir değer
                                         * eklendiğinde form eksik kalırdı. */
                                        foreach (TASK_PRIORITIES as $value => $label): ?>
                                            <input type="radio" name="priority" id="priority_<?= e($value) ?>"
                                                   value="<?= e($value) ?>" class="cy-priority-picker__input"
                                                   <?= $value === 'orta' ? 'checked' : '' ?>>
                                            <label for="priority_<?= e($value) ?>"
                                                   class="cy-priority-picker__option cy-priority-picker__option--<?= e($value) ?>">
                                                <?= e($label) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="invalid-feedback" data-error-for="priority"></div>
                                </div>

                                <div class="mb-0">
                                    <label for="due_date" class="form-label">Son Teslim</label>
                                    <!--
                                        type="date" tarayıcının takvimini açar ve değeri
                                        her zaman YYYY-MM-DD biçiminde gönderir; sunucudaki
                                        validate_due_date() bu biçimi zaten kabul eder.
                                    -->
                                    <input type="date" name="due_date" id="due_date" class="form-control">
                                    <div class="invalid-feedback" data-error-for="due_date"></div>
                                </div>
                            </aside>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary cy-btn" data-bs-dismiss="modal">
                            İptal
                        </button>
                        <button type="submit" id="submit_button" class="btn cy-btn cy-btn--primary">
                            <span class="spinner-border spinner-border-sm me-1 d-none" id="submit_spinner"
                                  role="status" aria-hidden="true"></span>
                            <span id="submit_label">Kaydet</span>
                        </button>
                    </div>

                    <!-- GİZLİ ALANLAR -->
                    <input type="hidden" name="action"     id="form_action" value="add">
                    <input type="hidden" name="task_id"    id="task_id"     value="">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                </div>
            </form>
        </div>
    </div>


    <!-- ================================================================
         MODAL 2 – SİLME ONAYI
         ================================================================ -->
    <div class="modal fade cy-modal" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">

                <div class="modal-header">
                    <h2 class="modal-title h6 mb-0" id="deleteModalLabel">Görevi Sil</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>

                <div class="modal-body text-center">
                    <div class="delete-icon" aria-hidden="true">!</div>
                    <p class="mb-0">
                        <strong id="delete_label"></strong> görevi
                        <u>kalıcı olarak</u> silinecek.
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary cy-btn btn-sm" data-bs-dismiss="modal">
                        Vazgeç
                    </button>
                    <button type="button" class="btn btn-danger cy-btn btn-sm" id="confirm_delete">
                        Evet, Sil
                    </button>
                </div>
            </div>
        </div>
    </div>


    <div class="toast-container cy-toast-container position-fixed top-0 end-0 p-3" id="toast_container"></div>


    <script src="assets/js/jquery-3.7.0.js"></script>
    <script src="assets/js/bootstrap.bundle.js"></script>
    <!-- Aynı önbellek kırıcı gerekçesi board.js için de geçerli. -->
    <script src="assets/js/board.js?v=<?= filemtime(__DIR__ . '/assets/js/board.js') ?>"></script>
    <script>
        // Sunucu tarafındaki ayarları JavaScript'e aktar.
        // Sabitleri iki yerde ayrı ayrı yazmak, birinin unutulmasıyla
        // sessiz uyumsuzluklara yol açar.
        CyBoard.init({
            endpoint:   'system/ajax.php',
            csrfToken:  <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>,
            priorities: <?= json_encode(TASK_PRIORITIES, JSON_UNESCAPED_UNICODE) ?>
        });
    </script>
</body>
</html>
