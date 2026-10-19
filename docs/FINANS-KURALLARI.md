# Finans kuralları — neyin neyi etkilediği

Tüm tutarlar küçük birimde tam sayıdır (kuruş/cent). TL, EUR, USD **asla toplanmaz**; özetler para birimi bazındadır. Ortak para birimine çevrilmiş görünüm ayrıdır ve kullanılan **kur + tarih** satırda yazılır.

| İşlem | Banka/kasa bakiyesi | Gelir/gider (kâr) | Alacak/borç |
|---|---|---|---|
| Hesap açılış bakiyesi | başlangıç değeri | ❌ gelir değil | – |
| Gelir / gider hareketi | ± | ✅ (KDV hariç) | – |
| Alacak/borç kalemi (plan) | ❌ değişmez | yalnızca `affects_pl` ise (hakediş, şahsi fatura, şablon) | ✅ doğar |
| Tahsilat / ödeme | ± (yalnızca gerçekleşince) | ❌ (çift sayım yok) | kalan azalır |
| Satış/alış faturası kesme | ❌ | ✅ KDV hariç tahakkuk | ✅ tek kalem (DB UNIQUE) |
| Fatura iptali | – | geri alınır | kalem iptal (ödemesi varsa reddedilir) |
| Transfer (iki bacak, atomik) | kaynak −, hedef + | ❌ | – |
| Döviz alım-satım | iki hesap | ❌ (yalnızca masraf gider) | – |
| Kart alışverişi | ❌ | ✅ alışveriş tarihinde (KDV hariç) | kart borcu + taksit takvimi |
| Kart ekstre ödemesi | − | ❌ (ikinci gider yok) | kart borcu azalır (FIFO) |
| Kredi kullandırım | + | ❌ gelir değil | kredi borcu |
| Kredi taksidi | − (anapara+faiz) | ✅ yalnızca **faiz** | anapara azalır |
| Şahsi fatura | ödenince − | yalnızca şahsi kapsamda | takvimde |
| Tekrarlayan şablon | ❌ | planlı kalem | planlı kalem üretir (tekil: şablon+vade) |
| Stoklu alış | ❌ | ❌ (envanter) | borç |
| Stok tüketimi / satışta çıkış | – | ✅ ortalama maliyetle, **bir kez** | – |
| Bakiye düzeltme | ± | ❌ | – |
| İptal (void) | geri alınır | geri alınır | geri alınır — kayıt silinmez |

- **Kâr ≠ nakit:** kâr = tahakkuk eden gelir − gider; nakit = hesaplardaki gerçekleşmiş hareket. Planlanan tahsilatlar "eldeki para" sayılmaz; panoda ayrı gösterilir.
- **KDV:** oranlar kodda sabit değil, ayarlardan; hesaplanan (satış) − indirilecek (alış/gider) oran bazında dökülür. Yönetim dökümüdür, resmî beyanname değildir.
- **Kapsam:** raporlar `Yalnızca şirketler / Yalnızca şahsi / Hepsi` ile ayrılır; şahsi giderler şirket giderine otomatik eklenmez.
- **Gerçekleşen işlem tarihi ileri tarihli olamaz**; ileri tarihli ödemeler plan (alacak/borç) olarak girilir. Hesabın açılış tarihinden önce işlem girilemez.
- **Kısmi ödeme / fazla ödeme:** satır kilidi altında kalan kontrol edilir; kalandan fazlası reddedilir; para birimi uyuşmazlığı reddedilir.

## Ödeme dağıtımı, avans, vade, stok, şahsi hesap (migration 003–004)
- **Tek para hareketi, çok dağıtım:** ödeme/tahsilat `transactions` içinde TEK kayıttır; faturalarla eşleşmesi `payment_allocations` tablosundadır. Dağıtım eklemek/geri almak/yeniden düzenlemek **para hareketi üretmez**, kasa/banka bakiyesini değiştirmez. Dağıtılmayan kısım cari hesapta **avans / eşleştirilmemiş** durur (fatura borcu veya alacağı sayılmaz) ve sonradan faturaya eşleştirilir.
- **Gelir ekranından müşteri seçilirse:** tutar müşteri **tahsilatı/avansı** olarak cari hesaba işlenir ve **gelir sayılmaz** (gelir fatura ile tahakkuk eder → çift sayım yok). Cari ile ilgisi olmayan gelir için “bağımsız gelir” işaretlenir (`standalone`). Eski hatalı kayıtlar “Cari tahsilatına çevir” ile düzeltilir (para hareketi aynı kalır, denetim kaydı yazılır).
- **Cari ekstre bakiyesi:** + müşteri bize borçlu / − biz borçluyuz. Faturalar +/−, tahsilat/ödeme tek satır, iptaller 0. Avans ayrı gösterilir ve net bakiyeden düşülür (cari raporu).
- **Vade isteğe bağlıdır** (NULL = vadesiz, gecikmiş sayılmaz, sessiz 30 gün yok). **Planlanan tarih** (`entries.planned_date`) vadeden ayrıdır; boşsa “tarih girilmemiş”.
- **Ana sayfa:** yalnızca işletme alanları; kasa+banka = işletme hesapları; alacak/borç = açık kalemlerin kalanı (aktif dağıtımlar düşülmüş, şahsi fatura hariç); avanslar ayrı. Liste anahtar tarihi: planlanan tarih, yoksa vade, yoksa tarihsiz. Sıra: gecikmiş → bugün → yaklaşan → tarihsiz; tamamlananlar listelenmez.
- **Stok/teslimat:** fatura ≠ teslimat ≠ ödeme. Satırdaki `fulfilled_milli` işlenen miktardır; stok hareketi yalnızca fark kadar yazılır (tekrar kaydetme ikinci hareket üretmez). Negatif stok varsayılan kapalı; stokta olmayan satış kaydedilir, eksik miktar “bekleyen teslimat” olur.
- **Şahsi hesap:** işletme adına şahsi hesaptan ödeme işletme bankasını azaltmaz; `for_workspace_id` ile işaretlenir, ortak cari (işletmenin sahibe borcu) hesabını besler. Şahsi↔işletme transferi `owner_in/owner_out` (gelir/gider değil).
- **Silme:** geçmişi olmayan hatalı kayıt silinir; ödeme dağıtımı/hareketi olan kayıt silinmez (iptal/arşiv/ters kayıt).
