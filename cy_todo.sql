-- ===============================================================
--  Sürükle-Bırak Görev Panosu  |  Veritabanı Kurulum Dosyası
--  cilginyazilim.com
-- ---------------------------------------------------------------
--  KURULUM (iki yoldan biri):
--    1) Terminal :  mysql -u root -p < cy_todo.sql
--    2) phpMyAdmin > İçe Aktar > Dosya seç > cy_todo.sql > Başlat
--
--  NOT: Bu dosya veritabanını da oluşturur, ayrıca elle
--       "cy_todo" veritabanı açmanıza gerek yoktur.
-- ===============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+03:00";
SET NAMES utf8mb4;

-- ---------------------------------------------------------------
--  1) Veritabanı
-- ---------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `cy_todo`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `cy_todo`;

-- Yabancı anahtar (foreign key) bağımlılığı yüzünden önce ÇOCUK
-- tablo silinir; tersi sırada MySQL hata verir.
DROP TABLE IF EXISTS `tasks`;
DROP TABLE IF EXISTS `task_columns`;

-- ---------------------------------------------------------------
--  2) task_columns – Panodaki sütunlar
-- ---------------------------------------------------------------
--  NEDEN SÜTUNLAR DA TABLODA?
--  "Yapılacak / Yapılıyor / Tamamlandı" durumlarını görevin içinde
--  bir ENUM olarak tutmak ilk bakışta daha basit görünür. Ama o
--  zaman yeni bir sütun eklemek için ALTER TABLE gerekir ve sütun
--  sırası, rengi, başlığı gibi bilgilere yer kalmaz. Ayrı tablo,
--  panoyu VERİYLE şekillendirilebilir hâle getirir.
--
--  NOT: Tablo adı `columns` DEĞİL `task_columns`. "columns" MySQL'de
--  ayrılmış bir kelimedir; her sorguda ters tırnak zorunluluğu
--  getirir ve er geç unutulup hata verir.
-- ---------------------------------------------------------------
CREATE TABLE `task_columns` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `title`      VARCHAR(60)  NOT NULL,

  -- Sütun başlığındaki renk şeridi (#rrggbb).
  `accent`     CHAR(7)      NOT NULL DEFAULT '#0b5cb5',

  -- Panodaki soldan sağa sırası.
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,

  /* Bu sütun "bitti" anlamına mı geliyor?
   * Arayüz, buraya düşen görevlerin başlığını üstü çizili gösterir
   * ve son teslim tarihi geçmiş olsa bile kırmızı uyarı vermez.
   * Sütunun ADINA bakarak ("Tamamlandı" mı?) karar vermek kırılgan
   * olurdu; kullanıcı sütunu yeniden adlandırdığında bozulurdu. */
  `is_done`    TINYINT(1)   NOT NULL DEFAULT 0,

  PRIMARY KEY (`id`),
  KEY `idx_columns_sort` (`sort_order`)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
--  3) tasks – Görev kartları
-- ---------------------------------------------------------------
CREATE TABLE `tasks` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `column_id`   INT UNSIGNED NOT NULL,

  `title`       VARCHAR(160) NOT NULL,
  `description` TEXT         NULL,

  /* ÖNCELİK NEDEN ENUM?
   * Üç sabit değerden birini alır ve bu liste kolay kolay
   * değişmez. ENUM, hem yer kaplamaz hem de veritabanı seviyesinde
   * "dusuk/orta/yuksek dışında bir değer giremezsin" güvencesi
   * verir. Sık değişecek listeler için ise ayrı tablo doğru olurdu.
   *
   * Değerler Türkçe karaktersiz yazıldı: URL, JSON ve CSS sınıf adı
   * olarak da kullanıldıkları için ASCII kalmaları işi kolaylaştırır. */
  `priority`    ENUM('dusuk','orta','yuksek') NOT NULL DEFAULT 'orta',

  `due_date`    DATE         DEFAULT NULL,

  /* SÜTUN İÇİNDEKİ SIRA.
   * Sürükle-bırakın kalıcı olmasını sağlayan alan budur.
   * 0'dan başlar ve her sütun kendi içinde numaralanır.
   *
   * Neden kesirli sayı (0.5 gibi araya sıkıştırma) kullanmıyoruz?
   * Kesirli yaklaşım tek satır güncellemesiyle daha hızlıdır ama
   * zamanla hassasiyet tükenir ve yeniden numaralandırma gerekir.
   * Bir panoda sütun başına birkaç yüz kart olur; tamsayıları
   * tek transaction'da yeniden yazmak hem basit hem yeterince
   * hızlıdır ve her zaman tutarlıdır. */
  `sort_order`  INT UNSIGNED NOT NULL DEFAULT 0,

  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- ON UPDATE CURRENT_TIMESTAMP: Kayıt her değiştiğinde MySQL bu
  -- alanı kendisi günceller; PHP tarafında unutma ihtimali kalmaz.
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  /* BİLEŞİK İNDEKS: Panoyu çizen sorgu tam olarak budur:
   *   WHERE column_id = ? ORDER BY sort_order
   * İki sütunu TEK indekste, bu sırayla tutmak, MySQL'in hem
   * filtrelemeyi hem sıralamayı indeks üzerinden yapmasını sağlar. */
  KEY `idx_tasks_column_sort` (`column_id`, `sort_order`),
  KEY `idx_tasks_due` (`due_date`),

  /* ON DELETE CASCADE: Bir sütun silinirse içindeki görevler de
   * silinir. Bu olmasaydı, hiçbir sütuna ait olmayan "yetim"
   * görevler veritabanında birikirdi ve hiçbir ekranda görünmezdi. */
  CONSTRAINT `fk_tasks_column`
    FOREIGN KEY (`column_id`) REFERENCES `task_columns` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
--  4) Örnek pano
-- ---------------------------------------------------------------
INSERT INTO `task_columns` (`id`, `title`, `accent`, `sort_order`, `is_done`) VALUES
(1, 'Yapılacak',   '#64748b', 0, 0),
(2, 'Yapılıyor',   '#0b5cb5', 1, 0),
(3, 'İncelemede',  '#d97706', 2, 0),
(4, 'Tamamlandı',  '#059669', 3, 1);

-- Örnek görevler: bir kısmı son teslim tarihi GEÇMİŞ, bir kısmı
-- tarihsiz bırakıldı; böylece arayüzdeki gecikme uyarısını ve
-- "tarihsiz kart" görünümünü de örnek üzerinde görebilirsiniz.
INSERT INTO `tasks` (`column_id`, `title`, `description`, `priority`, `due_date`, `sort_order`) VALUES
(1, 'Ödeme sağlayıcısı entegrasyonu',
    'iyzico ve PayTR karşılaştırılacak; komisyon oranları ve iade akışı raporlanacak.',
    'yuksek', '2026-08-22', 0),
(1, 'E-posta şablonlarını yenile',
    'Karşılama, şifre sıfırlama ve sipariş onayı şablonları mobil uyumlu hâle getirilecek.',
    'orta', '2026-09-05', 1),
(1, 'Eski kayıtları arşivle',
    NULL,
    'dusuk', NULL, 2),
(1, 'Çerez politikası metni',
    'Hukuk ekibinden gelen son metin siteye eklenecek.',
    'orta', '2026-08-10', 3),

(2, 'Sürükle-bırak panosu',
    'Kartlar sütunlar arasında taşınabilecek, sıra veritabanında saklanacak. Dokunmatik cihazlarda da çalışmalı.',
    'yuksek', '2026-08-18', 0),
(2, 'Excel içe aktarma önizlemesi',
    'Hatalı satırlar kırmızı gösterilecek, kullanıcı onaylamadan hiçbir şey kaydedilmeyecek.',
    'yuksek', '2026-08-16', 1),
(2, 'Karanlık tema düzeltmeleri',
    'Rozet ve kenarlık renkleri karanlık temada yeterince ayrışmıyor.',
    'dusuk', NULL, 2),

(3, 'Rol tabanlı yetkilendirme',
    'Editör rolünün silme yetkisi olmadığı test edilecek.',
    'orta', '2026-08-14', 0),
(3, 'Dosya yükleme güvenlik testi',
    'Çift uzantılı dosya ve sahte MIME denemeleri yapılacak.',
    'yuksek', '2026-08-12', 1),

(4, 'Veritabanı şeması',
    'utf8mb4, InnoDB ve gerekli indeksler tamamlandı.',
    'orta', '2026-07-28', 0),
(4, 'CSRF koruması',
    'Tüm POST uç noktaları oturuma bağlı anahtarla doğrulanıyor.',
    'yuksek', '2026-07-30', 1),
(4, 'Marka tasarım kalıbı',
    'cilginyazilim.css tüm projelerde ortak kullanılıyor.',
    'dusuk', NULL, 2);
