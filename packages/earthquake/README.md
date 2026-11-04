# jmaxml/earthquake

気象庁防災情報XMLの地震の電文を地震イベントにまとめ、「登録した市町村のうち、震度いくつ以上だったのはどこか」「取り消されたか」を判定する **非公式** PHP ライブラリです。気象庁とは関係ありません。

An **unofficial** PHP library that groups JMA (Japan Meteorological Agency) earthquake telegrams into events and tells you which registered cities may have reached a given seismic intensity, and whether the event was cancelled. Not affiliated with JMA.

- 対象の電文：震度速報（VXSE51）、震源に関する情報（VXSE52）、震源・震度に関する情報（VXSE53）
- [`jmaxml/core`](../core) で読んだ `Report` を使い、市町村と細分区域の対応は [`jmaxml/codes`](../codes) で引きます
- 状態は持ちません。同じ地震の電文を保存しておき、新しい電文が届くたびにイベントを組み立て直します

## インストール

```bash
composer require jmaxml/earthquake
```

PHP 8.4 以上が必要です。電文の取得には [`jmaxml/feed`](../feed) が使えます。

## 使い方

```php
use JmaXml\Earthquake\EarthquakeEvent;
use JmaXml\Earthquake\EarthquakeEvents;
use JmaXml\Earthquake\SeismicIntensity;

// $reports：同じ地震の電文（JmaXml\Report）。feed から受け取ったものを保存しておき、届くたびに全部で組み立て直す
$event = EarthquakeEvent::fromReports($reports);

if ($event->isCancelled()) {
    // 取り消された地震。送った安否確認があれば、取消を知らせる
}

$result = $event->citiesAtLeast(SeismicIntensity::FiveLower, $registeredCityCodes);

foreach ($result->matches as $match) {
    // $match->cityCode の登録者に安否確認を送る
    // $match->intensity：市町村（または細分区域）の震度。$match->notYetReceived なら「5弱以上の可能性あり、観測値未着」
    // $match->basis：MatchBasis::City（市町村の震度）か MatchBasis::Area（震度速報の細分区域から当てはめた）
}

foreach ($result->unknownCityCodes as $code) {
    // コード表に無い市町村コード。どの段階でも照合できないので、登録を見直す（黙って見送らない）
}
```

いろいろな電文がまとめて届くときは、`EarthquakeEvents::group($reports)` で地震ごとに分けられます。地震の電文以外は無視し、既定では運用種別が「通常」の電文だけを使います（訓練も扱うときは `EarthquakeEvents::group($reports, ['通常', '訓練'])`）。`EarthquakeEvent::fromReports()` は渡された電文をそのまま使うので、`$event->status` が「通常」かどうかは呼び出し側で確かめてください。

### 例：震度速報だけの段階

```php
$event = EarthquakeEvent::fromReports($shindoSokuhoReports);   // 震度速報 3 通（32-35_08 の地震 A）
$event->stage;                                                  // EventStage::IntensityOnly
$event->maxIntensity;                                           // SeismicIntensity::Four

$event->citiesAtLeast(SeismicIntensity::Four, ['0320100', '0110100'])->matches;
// [CityMatch('0320100', Four, false, MatchBasis::Area)]  盛岡市は細分区域「岩手県内陸北部」が震度 4
```

## 振る舞い

| 項目 | 振る舞い |
|---|---|
| 地震のまとめ方 | `Control/Status`（通常・訓練など）と `Head/EventID` が同じ電文を 1 つの地震にします。訓練の電文は別の地震になります |
| 最新の決め方 | 電文の種類（`Control/Title`）ごとに、`Head/ReportDateTime`、同じなら `Control/DateTime` が最も新しいものを使います。`Serial` は使いません。「訂正」は最新を置き換えるだけです |
| 取消 | 最新が「取消」の種類は使いません。すべての種類が取り消されたら `EventStage::Cancelled` で、どの市町村も一致しません |
| 段階 | `IntensityOnly`（震度速報だけ）、`WithHypocenter`（震源あり）、`WithHypocenterAndIntensity`（震源・震度あり）、`Cancelled` |
| 市町村の判定 | 震源・震度に関する情報があれば、市町村の震度で判定します。震度速報しか無ければ、細分区域の震度をその細分区域の市町村に当てはめます |
| あとから届いた震度速報 | 震源・震度に関する情報より新しい震度速報があれば、その細分区域も当てはめて判定に加えます（追加入電で震度が上がった細分区域を見落とさないため） |
| 未入電 | 「震度５弱以上未入電」の市町村は、どの基準の震度でも一致として返します（`notYetReceived === true`）。市町村の確定した震度が 5 弱以上でも、観測点のどれかが未入電なら同じ扱いです（実際の揺れは確定した震度より強いことがあるため）。`intensity` ではなく `notYetReceived` を見てください |
| コード表に無い市町村 | どの段階でも `unknownCityCodes` に入れます |
| 別の EventID の地震 | 1 つにまとめません。小さな地震が大きな地震の揺れで震度速報の基準に達すると、気象庁は別の EventID で震度速報を出すことがあります。`$a->isPossiblyRelatedTo($b)`（既定は検知時刻の差が 5 分以内で、震度 3 以上の細分区域が重なる）で関連の可能性を知らせるだけです。まとめるかどうかはアプリで決めてください |

## 注意

- 震度速報の段階の判定は細分区域単位なので、実際の市町村の震度より強めに出ることがあります（`MatchBasis::Area`）
- 遠くの地震（震源・震度に関する情報に国内の震度が無いもの）は、どの市町村も一致しません
- このライブラリは気象庁の公式ソフトウェアではありません。内容の正確性や即時性は保証しません。実際の防災行動は、気象庁や自治体などの公式の発表に従ってください

## 出典

出典：気象庁ホームページ「気象庁防災情報XMLフォーマット」（https://xml.kishou.go.jp/）

## ライセンス

MIT
