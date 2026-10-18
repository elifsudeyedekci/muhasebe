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
