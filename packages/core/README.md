# jmaxml/core

気象庁防災情報XMLの電文を、公式 XML スキーマから生成した型付きオブジェクトとして読む **非公式** PHP ライブラリです。気象庁とは関係ありません。

An **unofficial** PHP parser for JMA (Japan Meteorological Agency) disaster information XML. It reads every telegram into strongly typed, immutable objects generated from the official XML schema. Not affiliated with JMA.

- 管理部（Control）、ヘッダ部（Head）、気象・地震・火山の内容部（Body）をすべて型で表します（274 クラス）
- 公式サンプル電文 546 件がすべて読めることをテストで確認しています
- 実行時の依存は `ext-dom` と `ext-libxml` だけです

## インストール

```bash
composer require jmaxml/core
```

PHP 8.4 以上が必要です。

## 使い方

`Report::fromFile()`（または `Report::fromString()`）で読み、`$report->body` の型で内容部を判別します。

```php
use JmaXml\Meteorology;
use JmaXml\Report;
use JmaXml\Seismology;
use JmaXml\Volcanology;

$report = Report::fromFile('32-35_01_03_240613_VXSE53.xml');

echo $report->control->title;        // 震源・震度に関する情報
echo $report->head->eventID;         // 20091001134500

$body = $report->body;

if ($body instanceof Seismology\Body) {
    $earthquake = $body->earthquake[0];

    echo $earthquake->hypocenter?->area->name;     // 駿河湾
    echo $earthquake->magnitude[0]->description;   // Ｍ５．９
    echo $body->intensity?->observation?->maxInt;  // 5-

    $point = $earthquake->hypocenter?->area->coordinate[0]->points()[0];
    // $point->latitude 34.8, $point->longitude 138.5, $point->altitude -10000.0（深さ 10km）
} elseif ($body instanceof Meteorology\Body) {
    // 気象の内容部
} elseif ($body instanceof Volcanology\Body) {
    // 火山の内容部
}
```

### 管理部とヘッダ部だけを読む

`Envelope` は Body を組み立てません。電文の振り分けや、「取消」「訓練」の判定だけをしたいときに使います。

```php
use JmaXml\Envelope;

$envelope = Envelope::fromString($xml);

if ($envelope->control->status !== '通常' || $envelope->head->infoType === '取消') {
    // 訓練・試験の電文、または取消の電文
}
```

### 例外

読めない電文では `JmaXml\Exception\ParseException` を投げます。`path` プロパティで問題のある位置が分かります（例：`/Report/Head/Headline/Information[2]/Item`。添字は 1 始まり）。

## 読み込みの規則

XSD による検証はしません。次の方針で寛容に読みます。

- 未知の要素・属性は無視します。将来、要素が追加されても読み込みは失敗しません
- 子要素の順序は検査しません
- 必須の要素・属性が無い場合や、値を型に変換できない場合は `ParseException` になります
- 1 行の `xs:string` の値は、前後の空白（半角スペース・タブ・改行）を除きます。たとえば `<MaxInt>4 </MaxInt>` は `'4'`、`<Code>9074⏎</Code>` は `'9074'` になります。複数行にわたる平文は、レイアウトを保つためそのまま返します
- `xs:token`、数値、日時、期間、真偽値は、前後の空白を除いてから変換します
- 数値の `NaN`（演算してはいけない値）は `null` にします。`condition` や `description` などの属性はそのまま残ります
- 空要素は、`jmx_eb:nullablefloat` / `nullableinteger` なら `null`、文字列なら空文字列です
- `xsi:nil="true"` は、nillable な要素（`Head/TargetDateTime`）だけ `null` にします
- 時刻は電文に書かれたタイムゾーンのまま返します（管理部の `DateTime` は UTC、それ以外は JST）
- 座標（`Coordinate`・`Line`・`Polygon`）は元の文字列を `value` に保ち、`points()` で緯度・経度・高さに分解できます。度・度分・度分秒は `type` 属性で判定し、無ければ桁数で判定します
- DOCTYPE を含む文書は読みません

## XSD と PHP の対応

| XML 名前空間 | PHP 名前空間 |
|---|---|
| `http://xml.kishou.go.jp/jmaxml1/` | `JmaXml`（`Report`、`Control`） |
| `.../informationBasis1/` | `JmaXml\InformationBasis` |
| `.../elementBasis1/` | `JmaXml\ElementBasis` |
| `.../body/meteorology1/` | `JmaXml\Meteorology` |
| `.../body/seismology1/` | `JmaXml\Seismology` |
| `.../body/volcanology1/` | `JmaXml\Volcanology` |

- クラス名は、XSD の型名から `type.` を除いて先頭を大文字にしたものです（`type.volcanoInfo` → `VolcanoInfo`）
- プロパティ名は、要素名の先頭 1 文字だけを小文字にしたものです（`EventID` → `eventID`）。属性名はそのまま、simpleContent の値は `value` です

| XSD | PHP |
|---|---|
| `xs:string`、`xs:anyURI`、列挙 | `string`（1 行なら前後の空白を除く） |
| `xs:token` | `string`（空白を正規化） |
| `xs:dateTime` | `DateTimeImmutable` |
| `xs:duration` | `DateInterval` |
| `xs:float`、`jmx_eb:nullablefloat` | `?float` |
| `xs:int`、`xs:unsignedByte` | `int` |
| `jmx_eb:nullableinteger` | `?int` |
| `xs:boolean` | `bool` |
| `xs:list` | `list<string>` |

| 出現回数 | PHP |
|---|---|
| `1..1` | 非 null |
| `0..1` | nullable |
| `0..*`、`1..*` | `list<T>`（`1..*` は 1 件以上） |

`xs:any`（`jmx_add`）の中身は読みません。

## 注意

- このライブラリは気象庁の公式ソフトウェアではありません。内容の正確性や即時性は保証しません。実際の防災行動は、気象庁や自治体などの公式の発表に従ってください
- 気象庁の公開フィードはリアルタイムのサポートがなく、予告なく止まることがあります

## 出典

型は気象庁が公開している XML スキーマ（2024-10-31 版）と辞書（2026-01-29 版）から生成しています。

出典：気象庁ホームページ（https://xml.kishou.go.jp/）

## ライセンス

MIT
