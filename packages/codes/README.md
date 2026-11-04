# jmaxml/codes

気象庁防災情報XMLで使われる地震関係のコード表を、コードから引く **非公式** PHP ライブラリです。気象庁とは関係ありません。

An **unofficial** PHP library for the earthquake code tables of JMA (Japan Meteorological Agency) disaster information XML: prefectures, earthquake information areas, municipalities, seismic intensity stations, epicenter names and tsunami forecast areas. Not affiliated with JMA.

- 実行時の依存は PHP だけです（`jmaxml/core` にも依存しません）
- コード表は気象庁の公式資料から生成して同梱しています。実行時に xlsx は読みません

## インストール

```bash
composer require jmaxml/codes
```

PHP 8.3 以上が必要です。

## 使い方

```php
use JmaXml\Codes\EarthquakeCodes;

$city = EarthquakeCodes::city('1310100');
echo $city->name;                                  // 東京千代田区

$area = EarthquakeCodes::area($city->areaCode);
echo $area->name;                                  // 東京都２３区（細分区域 350）

echo EarthquakeCodes::prefecture($area->prefectureCode)->name;   // 東京都
```

### 震度速報を市町村で照合する

最初に届く震度速報（VXSE51）は、震度を **細分区域** 単位でしか伝えません。登録者を市町村コードで持っている場合は、市町村を細分区域に直してから照合します。

```php
// 登録者の市町村 → 細分区域
$city = EarthquakeCodes::city($registeredCityCode);

if ($city === null) {
    // この表に無い市町村コード。照合できないので、黙って見送らずに扱いを決める
    // （登録の見直しを促す、念のため対象に含める、など）
    throw new UnmappedCityException($registeredCityCode);
}

$areaCode = $city->areaCode;

// 逆に、震度速報に出た細分区域 → その中の市町村
$cities = EarthquakeCodes::citiesInArea('350');
```

続いて届く震源・震度に関する情報（VXSE53）には、市町村単位の震度も載ります。

> [!WARNING]
> `city()` が `null` を返す市町村コードは、震度速報とも、震源・震度に関する情報とも照合できません。照合に失敗すると、その人には何も届きません。次のようなコードは `null` になります。
>
> - **政令指定都市の市全体のコード**。たとえば `0110000`（札幌市）。地震情報では区単位（`0110100` 札幌中央区など）で扱われます
> - **区の再編や市町村合併で無くなったコード**。たとえば浜松市の区の再編（2024 年）より前の `2213100`。いまは `2213800`（浜松中央区）などです
> - 気象の情報にしか使われないコード（`AreaInformationCity` のうち、地震情報で使われないもの）
>
> 登録した時点で `city()` が `null` でないことを確かめてください。コード表を更新したときにも、すでに登録してあるコードを確かめ直してください。

## 収録している表

`地震火山関連コード表.xlsx`（版は `EarthquakeCodes::version()`、現在は 2026-10-19）から、次の表を収録しています。

| 気象庁のコード種別 | 引き方 | 件数 |
|---|---|---|
| 地震情報／都道府県等（AreaInformationPrefectureEarthquake） | `prefecture()`、`prefectures()` | 47 |
| 地震情報／細分区域（AreaForecastLocalE） | `area()`、`areasInPrefecture()` | 188 |
| 気象・地震・火山情報／市町村等（AreaInformationCity） | `city()`、`cities()`、`citiesInArea()` | 1,894 |
| 震度観測点（PointSeismicIntensity） | `station()`、`stationsInCity()` | 4,362 |
| 震央地名（AreaEpicenter） | `epicenter()` | 343 |
| 津波予報区（AreaTsunami） | `tsunamiArea()` | 98 |

- 市町村は、気象庁が地震情報で使う単位です。政令指定都市は区単位で、名前には `東京千代田区` のように地域名が付くことがあります
- 震度観測点のコードは、市町村コードと同じことがあります（`0123500` は市町村「石狩市」と観測点「石狩市花川」の両方）。表は別々に引きます
- コードは文字列の完全一致で引きます（前後の空白の除去や 0 埋めはしません）
- 表に無いコード（市町村合併前の古い電文など）は、`null` か空配列になります
- 一覧はすべてコード順です

## コード表の更新

気象庁のコード表は、観測点の移設や市町村の変更に合わせて月に 1 回ほど更新されます。このパッケージは、それに合わせて core とは別にリリースします。

開発者向け：`resources/jmaxml/codes/地震火山関連コード表.xlsx` を新しい版に差し替えて `composer generate` を実行すると、`src/Data/` が再生成されます。見出しの並び、コードの形式、階層に矛盾があれば、生成は止まります。

件数を確かめるテスト（`tools/codegen/tests/Codes/EarthquakeCodeTablesTest.php` と `packages/codes/tests/EarthquakeCodesTest.php`）は、意図した確認点です。毎月の更新で観測点などの件数が変わったら、新しい版の件数に合わせて直してください。

## 注意

- このライブラリは気象庁の公式ソフトウェアではありません。内容の正確性は保証しません。実際の防災行動は、気象庁や自治体などの公式の発表に従ってください

## 出典

出典：[気象庁ホームページ「気象庁防災情報XMLフォーマット」](https://xml.kishou.go.jp/)の個別コード表を加工して作成

収録しているデータは、気象庁ホームページの利用規約（政府標準利用規約（第2.0版）準拠）に従います。

## ライセンス

MIT
