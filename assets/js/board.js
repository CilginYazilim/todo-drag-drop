/* =====================================================================
 *  GÖREV PANOSU – Sürükle-Bırak Motoru ve Arayüz
 *  cilginyazilim.com – Sürükle-Bırak Görev Panosu
 * ---------------------------------------------------------------------
 *  NEDEN HAZIR BİR KÜTÜPHANE (SortableJS, jQuery UI) KULLANMIYORUZ?
 *  Sürükle-bırak, "nasıl çalıştığını anlamadan kullanılan" özelliklerin
 *  başında gelir. Buradaki kod yaklaşık 200 satırdır ve üç şeyi öğretir:
 *  imleç takibi, bırakma hedefinin bulunması ve sıranın kalıcı hâle
 *  getirilmesi. Gerçek bir projede hazır kütüphane kullanmak elbette
 *  makuldür; ama bu dosyayı bir kez okuduğunuzda o kütüphanenin ne
 *  yaptığını bilerek kullanırsınız.
 *
 *  NEDEN HTML5 draggable DEĞİL, POINTER EVENTS?
 *  Tarayıcıların yerleşik draggable API'si dokunmatik cihazlarda
 *  ÇALIŞMAZ ve sürüklenen öğenin görünümü üzerinde neredeyse hiç
 *  denetim vermez. Pointer Events ise fare, dokunmatik ve kalemi TEK
 *  olay kümesinde birleştirir: bir kez yazarsınız, üçünde de çalışır.
 *
 *  ERİŞİLEBİLİRLİK: Sürükle-bırak yalnızca fare/parmakla kullanılabilen
 *  bir özelliktir. Bu yüzden aynı işi yapan bir klavye yolu da vardır:
 *  karta odaklanıp Ctrl + ok tuşları. Sürükle-bırak eklerken klavye
 *  alternatifi yazmamak, özelliği bazı kullanıcılar için tamamen
 *  erişilemez kılar.
 * ================================================================== */

/* global jQuery, bootstrap */

var CyBoard = (function ($) {
    'use strict';

    /* =================================================================
     *  DURUM (STATE)
     * ============================================================== */

    var config = {
        endpoint:   'system/ajax.php',
        csrfToken:  '',
        priorities: {}
    };

    // Panonun son yüklenen hâli. Düzenleme formunu doldururken ve
    // klavye ile taşırken sütun listesine buradan bakılır.
    var board = { columns: [], total: 0 };

    var taskModal   = null;
    var deleteModal = null;

    var pendingDeleteId = null;
    var searchTimer     = null;

    /* Sürükleme sırasında tutulan bilgiler.
     * Tek bir nesnede toplamak, sürükleme bittiğinde her şeyi tek
     * satırda sıfırlamayı (resetDrag) mümkün kılar. */
    var drag = null;

    // İmleç bu kadar piksel hareket etmeden sürükleme BAŞLAMAZ.
    // Bu eşik olmasaydı, karta tıklamak bile minik bir sürüklemeye
    // dönüşür ve tıklama olayları yutulurdu.
    var DRAG_THRESHOLD = 5;

    // Kenara bu kadar yaklaşınca otomatik kaydırma devreye girer.
    var SCROLL_EDGE  = 60;
    var SCROLL_SPEED = 12;


    /* =================================================================
     *  YARDIMCILAR
     * ============================================================== */

    /**
     * Sağ üstte geçici bildirim (toast) gösterir.
     * @param {string} message
     * @param {string} type 'success' | 'danger' | 'info'
     */
    function notify(message, type) {
        var $toast = $(
            '<div class="toast cy-toast cy-toast--' + (type || 'success') + '"' +
                 ' role="alert" aria-live="assertive" aria-atomic="true">' +
                '<div class="d-flex">' +
                    '<div class="toast-body"></div>' +
                    '<button type="button" class="btn-close btn-close-white me-2 m-auto"' +
                          ' data-bs-dismiss="toast" aria-label="Kapat"></button>' +
                '</div>' +
            '</div>'
        );

        // ÖNEMLİ: .html() değil .text(). Mesaj içindeki HTML
        // çalışsaydı XSS açığı oluşurdu.
        $toast.find('.toast-body').text(message);
        $('#toast_container').append($toast);

        var toast = new bootstrap.Toast($toast[0], { delay: 3500 });
        // Kapanınca DOM'dan kaldır (bellek sızıntısını önler).
        $toast.on('hidden.bs.toast', function () { $toast.remove(); });
        toast.show();
    }

    /** Sunucuya POST isteği atar; CSRF anahtarını otomatik ekler. */
    function post(data) {
        data.csrf_token = config.csrfToken;

        return $.ajax({
            url: config.endpoint,
            method: 'POST',
            dataType: 'json',
            data: data
        });
    }

    /** Bir listedeki kart id'lerini sırasıyla döndürür. */
    function orderOf(list) {
        return $(list).children('.cy-task').map(function () {
            return $(this).data('id');
        }).get();
    }

    /** Sütun başlıklarındaki kart sayaçlarını tazeler. */
    function refreshCounts() {
        var total = 0;

        $('.cy-column').each(function () {
            var count = $(this).find('.cy-task').length;
            total += count;

            $(this).find('.cy-column__count').text(count);

            // Sütun boşaldıysa "buraya sürükleyin" ipucunu göster.
            $(this).find('.cy-column__empty').toggleClass('d-none', count > 0);
        });

        $('#total_tasks').text(total);
    }


    /* =================================================================
     *  ÇİZİM (RENDER)
     * -----------------------------------------------------------------
     *  TÜM METİNLER .text() İLE YAZILIR.
     *  Görev başlığı ve açıklaması kullanıcı girdisidir. .html()
     *  kullanılsaydı, başlığına <script> yazılmış bir görev bu sayfada
     *  kod çalıştırabilirdi (XSS). Sunucu tarafında da kaçış yapılır;
     *  iki katman birden olması bilinçli bir tercihtir.
     * ============================================================== */

    /** Tek bir görev kartını üretir. */
    function renderTask(task, isDoneColumn) {
        var $card = $('<article>', {
            'class': 'cy-task cy-task--' + task.priority,
            'data-id': task.id,
            // tabindex="0" : Kart klavyeyle odaklanabilir olur.
            // Bu olmadan Ctrl+ok kısayolları hiçbir zaman çalışmaz.
            tabindex: 0,
            role: 'listitem',
            'aria-label': task.title + ', öncelik ' + task.priority_label
        });

        /* Tutamaç (grip): Dokunmatik cihazlarda sürükleme YALNIZCA
         * buradan başlar. Kartın her yerinden sürüklenebilseydi,
         * telefonda sayfayı parmakla kaydırmak imkânsız olurdu. */
        $('<span>', {
            'class': 'cy-task__grip',
            'aria-hidden': 'true',
            title: 'Sürüklemek için tutun',
            text: '⠿'
        }).appendTo($card);

        var $body = $('<div>', { 'class': 'cy-task__body' }).appendTo($card);

        $('<h3>', {
            'class': 'cy-task__title' + (isDoneColumn ? ' cy-task__title--done' : ''),
            text: task.title
        }).appendTo($body);

        if (task.description) {
            $('<p>', { 'class': 'cy-task__desc', text: task.description }).appendTo($body);
        }

        var $meta = $('<div>', { 'class': 'cy-task__meta' }).appendTo($body);

        $('<span>', {
            'class': 'cy-priority cy-priority--' + task.priority,
            text: task.priority_label
        }).appendTo($meta);

        if (task.due_label) {
            /* Tamamlanan sütundaki kartlar gecikmiş sayılmaz: iş
             * bitmiştir, kırmızı uyarı yalnızca gürültü olur. */
            var overdue = task.is_overdue && !isDoneColumn;

            $('<span>', {
                'class': 'cy-due' + (overdue ? ' cy-due--overdue' : ''),
                text: (overdue ? '⚠ ' : '🗓 ') + task.due_label,
                title: overdue ? 'Son teslim tarihi geçti' : 'Son teslim tarihi'
            }).appendTo($meta);
        }

        var $actions = $('<div>', { 'class': 'cy-task__actions' }).appendTo($card);

        $('<button>', {
            type: 'button',
            'class': 'cy-btn-icon cy-btn-icon--edit js-edit',
            title: 'Düzenle',
            'aria-label': 'Düzenle: ' + task.title,
            html: '&#9998;'
        }).appendTo($actions);

        $('<button>', {
            type: 'button',
            'class': 'cy-btn-icon cy-btn-icon--delete js-delete',
            title: 'Sil',
            'aria-label': 'Sil: ' + task.title,
            html: '&#128465;'
        }).appendTo($actions);

        // Kart verisini elemana bağlıyoruz; düzenleme modalı ve
        // klavye taşıması sunucuya tekrar sormadan çalışsın.
        $card.data('task', task);

        return $card;
    }

    /** Tek bir sütunu (kartlarıyla birlikte) üretir. */
    function renderColumn(column) {
        var $column = $('<section>', {
            'class': 'cy-column',
            'data-column-id': column.id
        });

        /* CSS özel değişkeni: Sütunun rengi veritabanından gelir ve
         * stil dosyasına elle yazılmaz. Böylece yeni bir sütun
         * eklemek için CSS'e dokunmak gerekmez. */
        var $header = $('<header>', { 'class': 'cy-column__header' })
            .css('--cy-column-accent', column.accent)
            .appendTo($column);

        $('<span>', { 'class': 'cy-column__title', text: column.title }).appendTo($header);
        $('<span>', { 'class': 'cy-column__count', text: column.count }).appendTo($header);

        var $list = $('<div>', {
            'class': 'cy-column__list',
            'data-column-id': column.id,
            // is_done bilgisini DOM'a taşıyoruz: kart bu sütuna
            // bırakıldığında başlığının üstü çizilmeli mi, buradan bakılır.
            'data-is-done': column.is_done ? '1' : '0',
            role: 'list',
            'aria-label': column.title + ' sütunu'
        }).appendTo($column);

        $.each(column.tasks, function (_, task) {
            $list.append(renderTask(task, column.is_done));
        });

        // Boş sütun ipucu. Hiç kart yoksa görünür; sürüklerken
        // "buraya bırakabilirim" mesajını verir.
        $('<div>', {
            'class': 'cy-column__empty' + (column.count > 0 ? ' d-none' : ''),
            text: 'Buraya sürükleyin'
        }).appendTo($list);

        $('<button>', {
            type: 'button',
            'class': 'cy-column__add js-column-add',
            'data-column-id': column.id,
            text: '＋ Görev ekle'
        }).appendTo($column);

        return $column;
    }

    /** Panoyu baştan çizer. */
    function renderBoard() {
        var $board = $('#board').empty();

        if (board.columns.length === 0) {
            $board.append($('<div>', {
                'class': 'cy-board__loading',
                text: 'Pano boş. Önce cy_todo.sql dosyasını içe aktarın.'
            }));
            return;
        }

        $.each(board.columns, function (_, column) {
            $board.append(renderColumn(column));
        });

        // Formdaki sütun listesini de tazele.
        var $select = $('#column_id').empty();

        $.each(board.columns, function (_, column) {
            // data-accent: sütunun rengini seçeneğe taşır. Kullanıcı
            // bir sütun seçtiğinde formdaki kutunun kenarı o rengi
            // alır (bkz. updateColumnAccent()) — panodaki renkli
            // sütun başlıklarıyla aynı dili konuşur.
            $select.append($('<option>', {
                value: column.id,
                text: column.title,
                'data-accent': column.accent
            }));
        });

        updateColumnAccent();
        refreshCounts();
    }

    /**
     * Seçili sütunun rengini #column_id kutusunun kenarına yansıtır.
     *
     * NEDEN? Panodaki her sütunun kendine ait bir rengi var (başlık
     * şeridi). Formda "hangi sütuna ekliyorum?" sorusunun cevabını
     * yalnızca metinden değil, aynı renk ipucundan da almak, kullanıcının
     * panoyla formu zihninde daha hızlı eşleştirmesini sağlar.
     */
    function updateColumnAccent() {
        var accent = $('#column_id option:selected').data('accent') || '';
        $('#column_id').css('--cy-select-accent', accent);
    }

    /* NOT: Öncelik için ayrı bir "updatePriorityAccent()" fonksiyonu
     * YOKTUR. Öncelik artık bir <select> değil, renkli radio-pill
     * grubudur (bkz. index.php #priority); rengi tarayıcının kendi
     * :checked durumu belirler (bkz. style.css .cy-priority-picker__input:checked).
     * JavaScript'in rengi hesaplayıp yazması gerekmez — CSS zaten
     * "hangi radio işaretliyse o rengi göster" kuralını uygular. */

    /** Panoyu sunucudan yükler. */
    function loadBoard(search) {
        return post({ action: 'board', search: search || $('#search_input').val() || '' })
            .done(function (response) {
                board = response;
                renderBoard();
            })
            .fail(function () {
                notify('Pano yüklenemedi.', 'danger');
            });
    }


    /* =================================================================
     *  SÜRÜKLE-BIRAK MOTORU
     * -----------------------------------------------------------------
     *  ÜÇ AŞAMA:
     *    1. pointerdown → başlangıç noktasını kaydet (henüz sürükleme YOK)
     *    2. pointermove → eşik aşılınca sürüklemeyi başlat, hedefi bul
     *    3. pointerup   → sırayı sunucuya yaz
     *
     *  KARTIN KENDİSİ YER TUTUCUDUR (placeholder).
     *  Ayrı bir yer tutucu elemanı üretmek yerine, sürüklenen kartın
     *  ta kendisini DOM içinde gezdiriyoruz; ekranda parmağın altında
     *  görünen şey ise onun bir KOPYASIDIR ("ghost"). Bu yaklaşımın
     *  büyük avantajı: bırakma anında yapılacak hiçbir iş kalmaz,
     *  kart zaten doğru yerdedir.
     * ============================================================== */

    /** Sürükleme durumunu temizler. */
    function resetDrag() {
        if (!drag) { return; }

        if (drag.ghost) {
            drag.ghost.remove();
        }

        $(drag.card).removeClass('cy-task--placeholder');
        $('body').removeClass('cy-dragging');
        $('.cy-column__list').removeClass('cy-column__list--active');

        document.removeEventListener('pointermove', onPointerMove);
        document.removeEventListener('pointerup', onPointerUp);
        document.removeEventListener('pointercancel', onPointerCancel);

        drag = null;
    }

    function onPointerDown(event) {
        // Yalnızca farenin SOL tuşu. Sağ tık menüsü açarken
        // sürükleme başlamamalı.
        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }

        var card = event.target.closest('.cy-task');

        if (!card) { return; }

        // Butonlara basınca sürükleme başlamasın; onlar kendi
        // işlerini yapsın (düzenle / sil).
        if (event.target.closest('button')) { return; }

        /* DOKUNMATİK KURALI: Parmakla sürükleme yalnızca tutamaçtan
         * başlar. Aksi hâlde kartın üzerinden sayfayı kaydırmak
         * imkânsız hâle gelir ve uygulama telefonda kullanılamaz. */
        if (event.pointerType === 'touch' && !event.target.closest('.cy-task__grip')) {
            return;
        }

        var rect = card.getBoundingClientRect();

        drag = {
            card:      card,
            ghost:     null,
            started:   false,
            startX:    event.clientX,
            startY:    event.clientY,
            // İmlecin kartın SOL ÜST köşesine olan uzaklığı. Bunu
            // saklamazsak kart, sürüklerken imlecin köşesine
            // "zıplar" ve tutuş noktası kaybolur.
            offsetX:   event.clientX - rect.left,
            offsetY:   event.clientY - rect.top,
            width:     rect.width,
            height:    rect.height,
            // İptal edilirse geri dönülecek konum.
            originList:  card.parentNode,
            originNext:  card.nextElementSibling
        };

        document.addEventListener('pointermove', onPointerMove);
        document.addEventListener('pointerup', onPointerUp);
        document.addEventListener('pointercancel', onPointerCancel);
    }

    function onPointerMove(event) {
        if (!drag) { return; }

        // --- Eşik aşıldı mı? ---
        if (!drag.started) {
            var moved = Math.abs(event.clientX - drag.startX)
                      + Math.abs(event.clientY - drag.startY);

            if (moved < DRAG_THRESHOLD) { return; }

            startDrag();
        }

        // Sürükleme başladıktan sonra tarayıcının metin seçmesini
        // ve sayfayı kaydırmasını engelle.
        event.preventDefault();

        moveGhost(event.clientX, event.clientY);
        updateDropTarget(event.clientX, event.clientY);
        autoScroll(event.clientX, event.clientY);
    }

    function startDrag() {
        drag.started = true;

        /* GHOST: Kartın kopyası. Sayfa akışının dışında (position:fixed)
         * durur ve imleci takip eder.
         * pointer-events:none (CSS'te) ŞARTTIR: aksi hâlde
         * elementFromPoint her zaman ghost'u bulur ve altındaki
         * gerçek sütunu asla göremeyiz. */
        var ghost = drag.card.cloneNode(true);

        ghost.classList.add('cy-task--ghost');
        ghost.style.width  = drag.width + 'px';
        ghost.style.height = drag.height + 'px';
        ghost.removeAttribute('tabindex');

        document.body.appendChild(ghost);
        drag.ghost = ghost;

        // Asıl kart yer tutucuya dönüşür: yerini korur ama soluklaşır.
        drag.card.classList.add('cy-task--placeholder');
        document.body.classList.add('cy-dragging');
    }

    function moveGhost(x, y) {
        drag.ghost.style.left = (x - drag.offsetX) + 'px';
        drag.ghost.style.top  = (y - drag.offsetY) + 'px';
    }

    /**
     * İmlecin altındaki sütunu bulur ve kartı doğru sıraya yerleştirir.
     *
     * EKLEME NOKTASI NASIL BULUNUR?
     * Sütundaki her kartın DİKEY ORTA NOKTASINA bakarız. İmleç,
     * bir kartın orta noktasının üstündeyse kart ondan ÖNCE, değilse
     * SONRA gelir. Bu basit kural, kartlar farklı yüksekliklerde
     * olsa bile doğru çalışır.
     */
    function updateDropTarget(x, y) {
        var element = document.elementFromPoint(x, y);
        var list    = element ? element.closest('.cy-column__list') : null;

        /* İmleç sütun başlığının veya "görev ekle" butonunun üzerindeyse
         * elementFromPoint listeyi bulamaz. Bu durumda sütunu bulup
         * onun listesini kullanıyoruz; kullanıcının niyeti bellidir. */
        if (!list && element) {
            var column = element.closest('.cy-column');
            list = column ? column.querySelector('.cy-column__list') : null;
        }

        if (!list) { return; }

        $('.cy-column__list').removeClass('cy-column__list--active');
        list.classList.add('cy-column__list--active');

        // Sürüklenen kartın kendisi hesaba katılmamalı.
        var siblings = Array.prototype.slice.call(
            list.querySelectorAll('.cy-task:not(.cy-task--placeholder)')
        );

        var before = null;

        for (var i = 0; i < siblings.length; i++) {
            var rect   = siblings[i].getBoundingClientRect();
            var middle = rect.top + rect.height / 2;

            if (y < middle) {
                before = siblings[i];
                break;
            }
        }

        if (before) {
            list.insertBefore(drag.card, before);
        } else {
            /* Sona ekle. "Buraya sürükleyin" ipucu her zaman en altta
             * kalsın diye ondan ÖNCE ekliyoruz. */
            var hint = list.querySelector('.cy-column__empty');
            list.insertBefore(drag.card, hint);
        }
    }

    /**
     * İmleç kenara yaklaşınca panoyu/sütunu otomatik kaydırır.
     *
     * Bu olmadan, ekrana sığmayan bir sütunun altına veya sağdaki
     * sütuna kart taşımak imkânsızdır: sürükleme sırasında normal
     * kaydırma çalışmaz.
     */
    function autoScroll(x, y) {
        // --- Dikey: içinde bulunulan sütun listesi ---
        var list = drag.card.parentNode;

        if (list && list.classList.contains('cy-column__list')) {
            var listRect = list.getBoundingClientRect();

            if (y < listRect.top + SCROLL_EDGE) {
                list.scrollTop -= SCROLL_SPEED;
            } else if (y > listRect.bottom - SCROLL_EDGE) {
                list.scrollTop += SCROLL_SPEED;
            }
        }

        // --- Yatay: panonun kendisi ---
        var boardElement = document.getElementById('board');
        var boardRect    = boardElement.getBoundingClientRect();

        if (x < boardRect.left + SCROLL_EDGE) {
            boardElement.scrollLeft -= SCROLL_SPEED;
        } else if (x > boardRect.right - SCROLL_EDGE) {
            boardElement.scrollLeft += SCROLL_SPEED;
        }
    }

    function onPointerUp() {
        if (!drag) { return; }

        // Eşik aşılmadıysa bu bir sürükleme değil, tıklamaydı.
        if (!drag.started) {
            resetDrag();
            return;
        }

        var card   = drag.card;
        var list   = card.parentNode;
        var taskId = $(card).data('id');

        resetDrag();

        persistMove(card, list, taskId);
    }

    function onPointerCancel() {
        // Sistem sürüklemeyi kesti (gelen çağrı, ekran kilidi...).
        // Kartı başladığı yere geri koy.
        if (drag && drag.started) {
            drag.originList.insertBefore(drag.card, drag.originNext);
        }

        resetDrag();
    }

    /**
     * Yeni sırayı sunucuya yazar.
     *
     * İYİMSER GÜNCELLEME (optimistic update):
     * Kart zaten ekranda yeni yerine taşındı; sunucunun onayını
     * beklemiyoruz. Beklemek, her bırakmada gözle görülür bir
     * donma yaratırdı. Karşılığında bir söz veriyoruz: istek
     * başarısız olursa panoyu sunucudan yeniden yükleyip ekranı
     * gerçeğe döndürürüz. İyimser güncellemeyi geri alma planı
     * olmadan kullanmak, kullanıcıya yalan söylemektir.
     */
    function persistMove(card, list, taskId) {
        var columnId = $(list).data('column-id');
        var isDone   = $(list).data('is-done') === 1 || $(list).data('is-done') === '1';

        // Başlığın üstü çizili görünümünü hemen güncelle.
        $(card).find('.cy-task__title').toggleClass('cy-task__title--done', isDone);

        refreshCounts();

        post({
            action:    'move',
            task_id:   taskId,
            column_id: columnId,
            order:     orderOf(list)
        })
        .fail(function (xhr) {
            var res = xhr.responseJSON || {};
            notify(res.description || 'Görev taşınamadı, pano yenileniyor.', 'danger');

            // Ekranı gerçeğe döndür.
            loadBoard();
        });
    }


    /* =================================================================
     *  KLAVYE İLE TAŞIMA
     * -----------------------------------------------------------------
     *  Sürükle-bırakın erişilebilir karşılığı.
     *    Ctrl + ↑ / ↓ : Kartı sütun içinde yukarı/aşağı taşır
     *    Ctrl + ← / → : Kartı önceki/sonraki sütuna taşır
     *
     *  Ctrl zorunlu tutuldu; yalnızca ok tuşları kullanılsaydı,
     *  kartlar arasında gezinmek isteyen klavye kullanıcısı istemeden
     *  panoyu yeniden düzenlerdi.
     * ============================================================== */
    function onCardKeyDown(event) {
        if (!event.ctrlKey) { return; }

        var keys = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];

        if (keys.indexOf(event.key) === -1) { return; }

        event.preventDefault();

        var card = event.currentTarget;
        var list = card.parentNode;

        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            /* --- Sütun içinde yukarı/aşağı --- */
            var sibling = event.key === 'ArrowUp'
                ? card.previousElementSibling
                : card.nextElementSibling;

            // "Buraya sürükleyin" ipucu bir kart değildir; atla.
            if (!sibling || !sibling.classList.contains('cy-task')) { return; }

            if (event.key === 'ArrowUp') {
                list.insertBefore(card, sibling);
            } else {
                list.insertBefore(sibling, card);
            }

            persistMove(card, list, $(card).data('id'));
        } else {
            /* --- Yan sütuna --- */
            var column     = list.closest('.cy-column');
            var nextColumn = event.key === 'ArrowLeft'
                ? column.previousElementSibling
                : column.nextElementSibling;

            if (!nextColumn) { return; }

            var targetList = nextColumn.querySelector('.cy-column__list');

            // Yeni sütunun sonuna ekle ("buraya sürükleyin" ipucundan önce).
            targetList.insertBefore(card, targetList.querySelector('.cy-column__empty'));

            persistMove(card, targetList, $(card).data('id'));

            notify('Görev "' + $(nextColumn).find('.cy-column__title').text() + '" sütununa taşındı.', 'info');
        }

        // Taşıdıktan sonra odağı kartta tut; kullanıcı arka arkaya
        // basarak kartı istediği yere götürebilsin.
        card.focus();
    }


    /* =================================================================
     *  FORM VE MODALLAR
     * ============================================================== */

    function clearErrors() {
        var $form = $('#task_form');
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $('#form_alert').addClass('d-none').text('');
    }

    function showErrors(errors, general) {
        clearErrors();

        if (general) {
            $('#form_alert').removeClass('d-none').text(general);
        }

        $.each(errors || {}, function (field, message) {
            $('#' + field).addClass('is-invalid');
            $('[data-error-for="' + field + '"]').text(message);
        });
    }

    function setLoading(isLoading) {
        $('#submit_button').prop('disabled', isLoading);
        $('#submit_spinner').toggleClass('d-none', !isLoading);
        $('#submit_label').text(isLoading ? 'Kaydediliyor…' : 'Kaydet');
    }

    /** Formu sıfırlar ve ekleme moduna alır. */
    function openAddModal(columnId) {
        // form.reset(): metin alanlarını temizler; radio'ları da
        // HTML'deki "checked" özniteliğine (yani "orta" önceliğine)
        // geri döndürür — ayrıca kod yazmaya gerek kalmaz.
        $('#task_form')[0].reset();
        $('#task_id').val('');          // Gizli alanları reset() temizlemez
        $('#form_action').val('add');
        $('#taskModalLabel').text('Yeni Görev');

        if (columnId) {
            $('#column_id').val(columnId);
        }

        updateColumnAccent();
        clearErrors();
        taskModal.show();
    }

    /** Formu mevcut görevle doldurup düzenleme moduna alır. */
    function openEditModal(task) {
        $('#task_form')[0].reset();
        $('#form_action').val('edit');
        $('#task_id').val(task.id);
        $('#title').val(task.title);
        $('#description').val(task.description);
        $('#column_id').val(task.column_id);
        // Öncelik artık <select> değil, radio grubu: doğru pilin
        // işaretli görünmesi için ilgili radio'yu bulup checked yapıyoruz.
        $('#priority_' + task.priority).prop('checked', true);
        $('#due_date').val(task.due_date || '');
        $('#taskModalLabel').text('Görevi Düzenle');

        updateColumnAccent();
        clearErrors();
        taskModal.show();
    }


    /* =================================================================
     *  OLAY BAĞLAMA
     * ============================================================== */
    function bindEvents() {
        var boardElement = document.getElementById('board');

        // Kullanıcı formda sütun değiştirdikçe, kutunun kenar rengi
        // de anında güncellensin. (Öncelik artık renkli seçim
        // kutuları olduğu için ayrı bir olaya ihtiyaç duymaz —
        // rengi tarayıcının :checked durumu belirler.)
        $('#column_id').on('change', updateColumnAccent);

        /* Sürükleme: Olayı panonun kendisine bağlıyoruz.
         * Kartlar AJAX ile SONRADAN oluşturulduğu için tek tek
         * bağlamak çalışmazdı ("event delegation"). */
        boardElement.addEventListener('pointerdown', onPointerDown);

        // Klavye kısayolları da aynı sebeple devredilerek bağlanır.
        $('#board').on('keydown', '.cy-task', onCardKeyDown);

        // Sürükleme sırasında Escape → iptal.
        $(document).on('keydown', function (event) {
            if (event.key === 'Escape' && drag && drag.started) {
                onPointerCancel();
            }
        });

        /* --- Kart butonları --- */
        $('#board').on('click', '.js-edit', function () {
            openEditModal($(this).closest('.cy-task').data('task'));
        });

        $('#board').on('click', '.js-delete', function () {
            var task = $(this).closest('.cy-task').data('task');

            pendingDeleteId = task.id;
            $('#delete_label').text(task.title);
            deleteModal.show();
        });

        // Kartın gövdesine çift tıklayınca da düzenleme açılsın.
        // Tek tıklama kullanılmadı: sürüklemeye hazırlanan kullanıcı
        // istemeden modal açardı.
        $('#board').on('dblclick', '.cy-task', function () {
            openEditModal($(this).data('task'));
        });

        /* --- Sütun altındaki "görev ekle" --- */
        $('#board').on('click', '.js-column-add', function () {
            openAddModal($(this).data('column-id'));
        });

        $('#add_button').on('click', function () { openAddModal(null); });

        /* --- Form gönderimi --- */
        $('#task_form').on('submit', function (event) {
            // Sayfanın yenilenmesini engelle; işi AJAX yapacak.
            event.preventDefault();

            clearErrors();
            setLoading(true);

            post($(this).serializeArray().reduce(function (data, field) {
                data[field.name] = field.value;
                return data;
            }, {}))
            .done(function (response) {
                taskModal.hide();
                notify(response.description, 'success');
                loadBoard();
            })
            .fail(function (xhr) {
                var res = xhr.responseJSON || {};
                showErrors(res.errors, res.description || 'İşlem tamamlanamadı.');
            })
            .always(function () {
                setLoading(false);
            });
        });

        /* --- Silme onayı --- */
        $('#confirm_delete').on('click', function () {
            if (pendingDeleteId === null) { return; }

            // Çift tıklamayı engellemek için butonu kilitle.
            var $button = $(this).prop('disabled', true);

            post({ action: 'delete', id: pendingDeleteId })
                .done(function (response) {
                    notify(response.description, 'success');
                    loadBoard();
                })
                .fail(function (xhr) {
                    var res = xhr.responseJSON || {};
                    notify(res.description || 'Görev silinemedi.', 'danger');
                })
                .always(function () {
                    $button.prop('disabled', false);
                    deleteModal.hide();
                    pendingDeleteId = null;
                });
        });

        /* --- Arama ---
         * GECİKTİRME (debounce): Her tuş vuruşunda sunucuya istek
         * atmak, "yazılım" kelimesi için 7 gereksiz sorgu demektir.
         * Kullanıcı 300 ms yazmayı bıraktığında tek istek atıyoruz. */
        $('#search_input').on('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { loadBoard(); }, 300);
        });
    }


    /* =================================================================
     *  BAŞLANGIÇ
     * ============================================================== */
    function init(options) {
        $.extend(config, options || {});

        $(function () {
            taskModal   = new bootstrap.Modal(document.getElementById('taskModal'));
            deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));

            bindEvents();
            loadBoard();
        });
    }

    // Dışarıya SADECE init veriliyor. Geri kalan her şey bu
    // fonksiyonun içinde kapalı kalır; sayfadaki başka bir script
    // yanlışlıkla iç durumu bozamaz. (Modül deseni / IIFE)
    return { init: init };

})(jQuery);
