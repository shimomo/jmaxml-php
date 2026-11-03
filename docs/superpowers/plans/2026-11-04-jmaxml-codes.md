# jmaxml/codes 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 気象庁の個別コード表のうち地震関係（都道府県等・細分区域・市町村等・震度観測点・震央地名・津波予報区）を、コードから名前や上位の区域を引ける `jmaxml/codes` として作る。

**Architecture:** 公式の `地震火山関連コード表.xlsx` を `tools/codegen`（core と同じジェネレーター）で読んで検証し、`packages/codes/src/Data/` に定数配列のクラスとして生成してコミットする。利用者向けには、手書きの値オブジェクトと静的な入口 `EarthquakeCodes` を用意する。実行時には xlsx を読まない。

**Tech Stack:** PHP ^8.4、PHPUnit ^13.2、Psalm ^6.0（errorLevel 1）、Laravel Pint ^1.27（core と同じ）

**Spec:** `docs/superpowers/specs/2026-11-03-jmaxml-php-design.md`（5.3 節）

**前提:** `jmaxml/core` は main にマージ済み（PR #1）。ジェネレーターの `XlsxReader`（`tools/codegen/src/Xlsx/XlsxReader.php`）、`Generator::MARKER`、`Fixtures`（`packages/core/tests/Support/Fixtures.php`）をそのまま使う。作業ブランチは `feat/codes`。

## Global Constraints

- Packagist 名は `jmaxml/codes`、PHP 名前空間は `JmaXml\Codes\`。`description` は `"Unofficial JMA (Japan Meteorological Agency) earthquake code tables for JMA XML"`
- codes の実行時依存は `php` だけ（core にも依存しない）
- コード表の版（気象庁のファイル日付）を、パッケージから参照できること。今回の版は **2026-10-19**
- 生成ファイルの先頭には core と同じマーカー（`Generator::MARKER`）を入れる。生成コードは `pint --test` と `psalm` をそのまま通ること
- 同梱するコード表は `resources/jmaxml/codes/地震火山関連コード表.xlsx`（`https://xml.kishou.go.jp/jmaxml_20261019_Code.zip` の中のファイルを無加工で）
- すべての PHP ファイルは `declare(strict_types=1);`。手書きクラスには `@author shimomo`

## 調査済みの事実（2026-10-19 版の xlsx）

- 使うシートは `更新履歴`、`23`（地震情報／都道府県等）、`24`（細分区域・市町村等・震度観測点）、`31`（津波予報区）、`41`（震央地名）
- 各シートは、A 列が `Code` の見出し行の次からがデータ。見出し行より上の行数はシートごとに違う
- シート `24` は 1 行に「細分区域（A〜C 列：Code・Name・ふりがな）・市町村（D〜F 列）・震度観測点（G〜I 列）」がそろった平らな表で、データは 4,362 行
  - 細分区域 188、市町村 1,894、震度観測点 4,362。市町村が複数の細分区域にまたがること、細分区域が複数の都道府県にまたがること、同じコードに別の名前が付くことは、いずれも無い
  - 市町村の 1,894 件は、気象庁が地震情報で使う市町村の全件と一致する（震度観測点の無い市町村は無い）。政令指定都市は区単位で、名前は `東京千代田区` のように地域名が付くことがある
  - 細分区域の都道府県は、その市町村コードの先頭 2 桁（全国地方公共団体コードの都道府県部分）で決まる
  - 震度観測点のコードが市町村コードと同じことがある（`0123500` は市町村「石狩市」と観測点「石狩市花川」の両方）。表は別々に持つ
- シート `23` は 47 件、`31` は 98 件（D 列の備考に `津波予報区`、`領域表現（構成する津波予報区800,801,802）` など）、`41` は 343 件
- 細分区域コード（シート `24` の A 列）はセルに数値として入っている（`<v>100</v>`）。都道府県 `01` や震央地名 `011` は文字列。値の前後の空白は現状なし
- `更新履歴` の B 列は和暦の日付（`H22.08.31` … `R8.10.19`）。最新は `R8.10.19` = 2026-10-19

## Review Focus

1. **数値として入ったコードの 0 落ち**：将来の版で `011` が数値 `11` として入るなど、桁数が崩れたコードは、生成を止めること（黙って `11` を出さない）→ Task 1 の `rejectsMalformedCodes`
2. **階層の矛盾**：同じ市町村が別の細分区域に、同じ細分区域が別の都道府県に、同じコードが別の名前で出てきたら、生成を止めること → Task 1 の `rejectsInconsistentHierarchy`
3. **見出し行の位置の変化**：見出し行の上に行が増えても、`Code` 行を探してデータを読めること。`Code` 行が無ければ止まること → Task 1 の `findsDataRowsBelowTheCodeHeader`
4. **古い電文のコード**：市町村合併などで今の表に無いコード（公式サンプルの 2010 年の電文など）を引いたときは、例外ではなく `null` や空配列を返すこと → Task 3 の `unknownCodesReturnNothing`
5. **値の前後の空白**：将来の版でセルに空白（全角スペースを含む）が混ざっても、名前とふりがなは前後を除いて出すこと → Task 1 の `trimsCellValues`

---

## ファイル構成

```
jmaxml-php/
├── composer.json                         Modify: replace / autoload に jmaxml/codes を足す
├── resources/jmaxml/
│   ├── README.md                         Modify: コード表の行を足す
│   └── codes/地震火山関連コード表.xlsx     Create: 公式のコード表
├── tools/codegen/
│   ├── bin/generate                      Modify: コード表も生成する
│   ├── src/GeneratedFiles.php            Create: 生成ファイルの書き込みと古いファイルの削除（Generator::writeTo から切り出す）
│   ├── src/Generator.php                 Modify: writeTo が GeneratedFiles を使う
│   ├── src/Codes/EarthquakeCodeTables.php Create: xlsx の読み込みと検証
│   ├── src/Codes/CodesGenerator.php      Create: Data クラスの出力
│   └── tests/Codes/{EarthquakeCodeTablesTest,CodeDataIsUpToDateTest}.php
├── packages/core/tests/Support/Fixtures.php  Modify: earthquakeCodeTablePath() を足す
└── packages/codes/
    ├── composer.json / LICENSE / README.md / .gitattributes
    ├── src/
    │   ├── EarthquakeCodes.php           手書き：静的な入口
    │   ├── {Prefecture,EarthquakeArea,City,IntensityStation,EpicenterArea,TsunamiArea}.php  手書き：値オブジェクト
    │   └── Data/*.php                    生成：定数配列（7 ファイル）
    └── tests/EarthquakeCodesTest.php
```

---

### Task 1: コード表の読み込みと検証（ジェネレーター側）

**Files:**
- Create: `resources/jmaxml/codes/地震火山関連コード表.xlsx`
- Modify: `resources/jmaxml/README.md`, `packages/core/tests/Support/Fixtures.php`
- Create: `tools/codegen/src/Codes/EarthquakeCodeTables.php`
- Test: `tools/codegen/tests/Codes/EarthquakeCodeTablesTest.php`

**Interfaces:**
- Consumes: `JmaXml\Codegen\Xlsx\XlsxReader::open()` / `rows()`（core の計画の Task 4）
- Produces:

```php
namespace JmaXml\Tests\Support;
// Fixtures に追加
public static function earthquakeCodeTablePath(): string; // resources/jmaxml/codes/地震火山関連コード表.xlsx

namespace JmaXml\Codegen\Codes;
final readonly class EarthquakeCodeTables
{
    /**
     * @param array<string, array{string}> $prefectures code => [name]
     * @param array<string, array{string, string, string}> $areas code => [name, kana, prefecture code]
     * @param array<string, array{string, string, string}> $cities code => [name, kana, area code]
     * @param array<string, array{string, string, string}> $stations code => [name, kana, city code]
     * @param array<string, array{string}> $epicenters code => [name]
     * @param array<string, array{string, string, string}> $tsunamiAreas code => [name, kana, note]
     */
    public function __construct(
        public string $version,          // 'YYYY-MM-DD'
        public array $prefectures,
        public array $areas,
        public array $cities,
        public array $stations,
        public array $epicenters,
        public array $tsunamiAreas,
    ) {}
    public static function fromXlsx(string $path): self;   // 下記のシートを読み、すべて検証する。すべての配列はコード順（SORT_STRING）
    /** @param list<array<string, string>> $rows @return list<array<string, string>> */
    public static function dataRows(array $rows, string $sheet): array;  // A 列が 'Code' の行の次から。無ければ \LogicException
    /** @param list<array<string, string>> $rows */
    public static function versionFrom(array $rows): string;           // 更新履歴の B 列の最新日付
    /** @param list<array<string, string>> $rows データ行 @return array{0: array<string, array{string, string, string}>, 1: array<string, array{string, string, string}>, 2: array<string, array{string, string, string}>} */
    public static function hierarchyFrom(array $rows): array;          // シート 24 → [areas, cities, stations]
}
```

  検証の規則（違反は `\LogicException`。メッセージにシート名とコードを入れる）：
  - コードの形式：都道府県 `/^\d{2}$/`、細分区域 `/^\d{3}$/`、市町村・震度観測点 `/^\d{7}$/`、震央地名 `/^\d{3}$/`、津波予報区 `/^\d{3}$/`
  - 名前は空でないこと。すべてのセル値は、前後の空白（全角スペースを含む。`/^[\s\x{3000}]+|[\s\x{3000}]+$/u`）を除いてから使う
  - 同じ表の中でコードが重複しないこと（シート 24 では、同じ細分区域・市町村が何行にも出るので、名前・ふりがな・上位コードが毎回同じであることを確かめる）
  - 細分区域の都道府県コードは、その細分区域に属する市町村コードの先頭 2 桁。1 つに決まらなければ止める。その都道府県コードが都道府県の表に無ければ止める
  - 和暦：`R{n}.MM.DD` は 2018+n 年、`H{n}.MM.DD` は 1988+n 年。それ以外の形式は止める（空の B 列や見出しの `年月日` は読み飛ばす）

- [ ] **Step 1: コード表を置く**

  `https://xml.kishou.go.jp/jmaxml_20261019_Code.zip` を空の一時ディレクトリに取得し、`unzip -O cp932` で展開して、`地震火山関連コード表.xlsx` だけを `resources/jmaxml/codes/` にコピーする。`resources/jmaxml/README.md` の表に「`codes/地震火山関連コード表.xlsx` ／ 個別コード表（地震火山関連） ／ 2026-10-19 ／ 上記 zip」の行を足す。`Fixtures::earthquakeCodeTablePath()` を足す。

- [ ] **Step 2: 失敗するテストを書く**

```php
#[Test] public function readsOfficialTables(): void
// $t = EarthquakeCodeTables::fromXlsx(Fixtures::earthquakeCodeTablePath());
// $t->version === '2026-10-19'
// count: prefectures 47, areas 188, cities 1894, stations 4362, epicenters 343, tsunamiAreas 98
// $t->prefectures['13'] === ['東京都']
// $t->areas['350'] === ['東京都２３区', 'とうきょうとにじゅうさんく', '13']
// $t->cities['1310100'] === ['東京千代田区', 'とうきょうちよだく', '350']
// $t->stations['1310100'] === ['東京千代田区大手町', 'とうきょうちよだくおおてまち', '1310100']
// $t->cities['0123500'][0] === '石狩市' かつ $t->stations['0123500'][0] === '石狩市花川'
// $t->epicenters['485'] === ['駿河湾']
// $t->tsunamiAreas['891'] === ['沖縄県地方', 'おきなわけんちほう', '領域表現（構成する津波予報区800,801,802）']
// array_keys($t->cities) はコード順（sort した結果と同じ）

#[Test] public function findsDataRowsBelowTheCodeHeader(): void
// dataRows([['A' => 'タイトル'], ['A' => '追加の行'], ['A' => 'Code', 'B' => 'Name'], ['A' => '01', 'B' => '北海道']], '23') === [['A' => '01', 'B' => '北海道']]
// dataRows([['A' => 'タイトル']], '23') → \LogicException

#[Test] public function convertsJapaneseEraDates(): void
// versionFrom([['B' => '年月日'], ['B' => 'H22.08.31'], ['B' => 'R8.10.19'], ['B' => 'R8.6.23'], []]) === '2026-10-19'
// versionFrom([['B' => '2026/10/19']]) → \LogicException

#[Test] public function rejectsMalformedCodes(): void
// hierarchyFrom([行(A: '100', D: '123500', G: '0123500')]) → \LogicException（市町村コードが 6 桁）

#[Test] public function rejectsInconsistentHierarchy(): void
// 同じ市町村 '0123500' が A '100' と A '101' の 2 行に出る → \LogicException
// 同じ細分区域 '100' に '01…' と '02…' の市町村が属する → \LogicException
// 同じ細分区域 '100' の名前が行によって違う → \LogicException

#[Test] public function trimsCellValues(): void
// hierarchyFrom([行(A: '100', B: '　石狩地方北部 ', C: 'いしかりちほうほくぶ', D: '0123500', E: '石狩市', F: 'いしかりし', G: '0123500', H: '石狩市花川', I: 'いしかりしはなかわ')])[0]['100'][0] === '石狩地方北部'
```

  ここでの「行(…)」は、`['A' => …, 'B' => …, …, 'I' => …]` の配列。テスト内の小さなヘルパーで作ってよい（未指定の名前・ふりがなは適当な非空文字列）。

- [ ] **Step 3: `vendor/bin/phpunit tools/codegen/tests/Codes` がクラス未定義で失敗することを確認する**

- [ ] **Step 4: `EarthquakeCodeTables` を実装する**（xlsx は `XlsxReader` で読む。外部ライブラリは使わない）

- [ ] **Step 5: テスト、psalm、pint が通ることを確認する**

- [ ] **Step 6: コミット**

```bash
git add resources/jmaxml packages/core/tests/Support/Fixtures.php tools/codegen/src/Codes tools/codegen/tests/Codes
git commit -m "feat(codegen): read and validate the JMA earthquake code tables"
```

---

### Task 2: codes パッケージの骨組みと Data クラスの生成

**Files:**
- Create: `packages/codes/{composer.json,LICENSE,README.md,.gitattributes}`（README は骨組みだけ。中身は Task 3）
- Modify: `composer.json`（ルート）
- Create: `tools/codegen/src/GeneratedFiles.php`、`tools/codegen/src/Codes/CodesGenerator.php`
- Modify: `tools/codegen/src/Generator.php`（`writeTo` の中身を `GeneratedFiles::write` に移す）、`tools/codegen/bin/generate`
- Create（生成）: `packages/codes/src/Data/{CodeTableVersion,PrefectureData,EarthquakeAreaData,CityData,IntensityStationData,EpicenterAreaData,TsunamiAreaData}.php`
- Test: `tools/codegen/tests/Codes/CodeDataIsUpToDateTest.php`、`tools/codegen/tests/GeneratedFilesTest.php`

**Interfaces:**
- Consumes: Task 1 の `EarthquakeCodeTables`
- Produces:

```php
namespace JmaXml\Codegen;
final class GeneratedFiles
{
    /**
     * 書き込み、$outputDir の中で MARKER を含み今回書かなかった .php を消す（Generator::writeTo の既存の挙動をそのまま移す）
     * @param array<string, string> $files 相対パス => 内容
     * @return list<string> 書いた相対パス（パス順）
     */
    public static function write(string $outputDir, array $files): array;
}

namespace JmaXml\Codegen\Codes;
final class CodesGenerator
{
    public function __construct(EarthquakeCodeTables $tables);
    /** @return array<string, string> packages/codes/src からの相対パス（'Data/CityData.php' など 7 件）=> 内容、パス順 */
    public function generate(): array;
}
```

  生成する Data クラスの形（後続の Task 3 が依存する。名前は変えない）：

```php
<?php

declare(strict_types=1);

// This file is generated by tools/codegen. Do not edit it by hand.

namespace JmaXml\Codes\Data;

/**
 * 気象・地震・火山情報／市町村等（AreaInformationCity）: code => [name, kana, area code]
 *
 * @internal
 */
final class CityData
{
    public const array ROWS = [
        '0110100' => ['札幌中央区', 'さっぽろちゅうおうく', '101'],
        // …コード順
    ];
}
```

  - `PrefectureData::ROWS`（code => [name]）、`EarthquakeAreaData::ROWS`（[name, kana, prefecture code]）、`CityData::ROWS`、`IntensityStationData::ROWS`（[name, kana, city code]）、`EpicenterAreaData::ROWS`（[name]）、`TsunamiAreaData::ROWS`（[name, kana, note]）
  - `CodeTableVersion::DATE = '2026-10-19'`（`public const string`）
  - docblock の 1 行目は、気象庁のコード種別名と英字のコード表名（地震情報／都道府県等 AreaInformationPrefectureEarthquake、地震情報／細分区域 AreaForecastLocalE、気象・地震・火山情報／市町村等 AreaInformationCity、震度観測点 PointSeismicIntensity、震央地名 AreaEpicenter、津波予報区 AreaTsunami）
  - 文字列はシングルクォート。`'` と `\` はエスケープする

- [ ] **Step 1: パッケージの骨組みを作る**

  `packages/codes/composer.json` は core と同じ形で、`name` は `jmaxml/codes`、`description` は Global Constraints の文言、`require` は `php ^8.4` だけ、autoload は `JmaXml\\Codes\\` → `src/`、autoload-dev は `JmaXml\\Codes\\Tests\\` → `tests/`。ルートの `composer.json` には `replace` に `"jmaxml/codes": "self.version"`、autoload に `"JmaXml\\Codes\\": "packages/codes/src/"`、autoload-dev に `"JmaXml\\Codes\\Tests\\": "packages/codes/tests/"` を足す。`LICENSE` と `.gitattributes` は core と同じ。`composer dump-autoload` と `composer normalize --dry-run` が通ることを確認する。

- [ ] **Step 2: 失敗するテストを書く**

```php
// tools/codegen/tests/GeneratedFilesTest.php
#[Test] public function writesFilesAndRemovesOnlyStaleGeneratedOnes(): void
// 一時ディレクトリに MARKER 入りの Stale.php とマーカーなしの Handwritten.php を置き、
// GeneratedFiles::write($dir, ['Data/A.php' => "<?php\n\n" . Generator::MARKER . "\n"]) === ['Data/A.php']
// Stale.php は消え、Handwritten.php と Data/A.php は残る

// tools/codegen/tests/Codes/CodeDataIsUpToDateTest.php
#[Test] public function committedCodeDataMatchesGenerator(): void
// $files = (new CodesGenerator(EarthquakeCodeTables::fromXlsx(Fixtures::earthquakeCodeTablePath())))->generate();
// array_keys($files) === ['Data/CityData.php', 'Data/CodeTableVersion.php', 'Data/EarthquakeAreaData.php', 'Data/EpicenterAreaData.php', 'Data/IntensityStationData.php', 'Data/PrefectureData.php', 'Data/TsunamiAreaData.php']
// 各ファイルの内容が packages/codes/src/{path} と一致する
// 各内容に Generator::MARKER が含まれる
```

- [ ] **Step 3: テストが失敗することを確認する**（`vendor/bin/phpunit tools/codegen/tests`）

- [ ] **Step 4: `GeneratedFiles` を実装し、`Generator::writeTo` をそれを呼ぶだけにする**（core 側の `GeneratorTest` と `GeneratedCodeIsUpToDateTest` は変更なしで通ること）

- [ ] **Step 5: `CodesGenerator` を実装し、`bin/generate` に追加する**

  `bin/generate` は core の生成のあとに、`EarthquakeCodeTables::fromXlsx(resources/jmaxml/codes/地震火山関連コード表.xlsx)` から `CodesGenerator` で生成し、`GeneratedFiles::write(packages/codes/src, …)` で書いて、`Generated 7 code table files.` を表示する。

- [ ] **Step 6: `composer generate` を実行する**

  Expected: `Generated 274 files.` と `Generated 7 code table files.`。core の生成コードに差分が出ないこと（`git status packages/core/src` が空）

- [ ] **Step 7: テスト一式、psalm、pint、composer normalize が通ることを確認する**

- [ ] **Step 8: コミット**

```bash
git add composer.json packages/codes tools/codegen
git commit -m "feat(codes): generate earthquake code table data"
```

---

### Task 3: 公開 API（値オブジェクトと EarthquakeCodes）とドキュメント

**Files:**
- Create: `packages/codes/src/{EarthquakeCodes,Prefecture,EarthquakeArea,City,IntensityStation,EpicenterArea,TsunamiArea}.php`
- Test: `packages/codes/tests/EarthquakeCodesTest.php`
- Modify: `packages/codes/README.md`、`README.md`（ルートのパッケージ表）、`CHANGELOG.md`

**Interfaces:**
- Consumes: Task 2 の `JmaXml\Codes\Data\*::ROWS` と `CodeTableVersion::DATE`
- Produces（feed・seismology の計画と安否確認アプリが依存する）:

```php
namespace JmaXml\Codes;
final readonly class Prefecture { public function __construct(public string $code, public string $name) {} }
final readonly class EarthquakeArea { public function __construct(public string $code, public string $name, public string $kana, public string $prefectureCode) {} }
final readonly class City { public function __construct(public string $code, public string $name, public string $kana, public string $areaCode) {} }
final readonly class IntensityStation { public function __construct(public string $code, public string $name, public string $kana, public string $cityCode) {} }
final readonly class EpicenterArea { public function __construct(public string $code, public string $name) {} }
final readonly class TsunamiArea { public function __construct(public string $code, public string $name, public string $kana, public string $note) {} }

final class EarthquakeCodes
{
    public static function version(): string;                          // CodeTableVersion::DATE
    public static function prefecture(string $code): ?Prefecture;
    public static function area(string $code): ?EarthquakeArea;        // 地震情報／細分区域
    public static function city(string $code): ?City;
    public static function station(string $code): ?IntensityStation;
    public static function epicenter(string $code): ?EpicenterArea;
    public static function tsunamiArea(string $code): ?TsunamiArea;
    /** @return list<Prefecture> */ public static function prefectures(): array;
    /** @return list<City> */ public static function cities(): array;
    /** @return list<EarthquakeArea> */ public static function areasInPrefecture(string $prefectureCode): array;
    /** @return list<City> */ public static function citiesInArea(string $areaCode): array;
    /** @return list<IntensityStation> */ public static function stationsInCity(string $cityCode): array;
}
```

  一覧を返すメソッドは、すべてコード順。コードは完全一致で引く（前後の空白の除去や 0 埋めはしない）。値オブジェクトは呼ぶたびに作ってよい。各クラスの docblock に、対応する気象庁のコード種別名（Task 2 の一覧）を書く。

- [ ] **Step 1: 失敗するテストを書く**

```php
#[Test] public function looksUpTheTokyoHierarchy(): void
// $city = EarthquakeCodes::city('1310100'); $city == new City('1310100', '東京千代田区', 'とうきょうちよだく', '350')
// EarthquakeCodes::area($city->areaCode) == new EarthquakeArea('350', '東京都２３区', 'とうきょうとにじゅうさんく', '13')
// EarthquakeCodes::prefecture('13') == new Prefecture('13', '東京都')

#[Test] public function keepsStationAndCityCodesApart(): void
// EarthquakeCodes::city('0123500')->name === '石狩市'、EarthquakeCodes::station('0123500')->name === '石狩市花川'、->cityCode === '0123500'

#[Test] public function listsChildrenInCodeOrder(): void
// array_map(fn($a) => $a->code, EarthquakeCodes::areasInPrefecture('03')) === ['210', '211', '212', '213']
// array_map(fn($c) => $c->code, EarthquakeCodes::citiesInArea('100')) === ['0123500', '0130300', '0130400']
// count(EarthquakeCodes::prefectures()) === 47、count(EarthquakeCodes::cities()) === 1894
// EarthquakeCodes::stationsInCity('0123500') の code の並びは '0123500' から始まり、すべて cityCode が '0123500'

#[Test] public function readsEpicentersAndTsunamiAreas(): void
// EarthquakeCodes::epicenter('485') == new EpicenterArea('485', '駿河湾')
// EarthquakeCodes::tsunamiArea('891')->note === '領域表現（構成する津波予報区800,801,802）'

#[Test] public function exposesTheCodeTableVersion(): void   // EarthquakeCodes::version() === '2026-10-19'

#[Test] public function unknownCodesReturnNothing(): void
// city('9999999')、area('999')、prefecture('99')、station('0000000')、epicenter('000')、tsunamiArea('000') はすべて null
// city(' 1310100') と city('1310100 ') も null（完全一致）
// citiesInArea('999') === []、areasInPrefecture('99') === []、stationsInCity('9999999') === []
```

- [ ] **Step 2: `vendor/bin/phpunit packages/codes/tests` が失敗することを確認する**

- [ ] **Step 3: 値オブジェクトと `EarthquakeCodes` を実装する**

- [ ] **Step 4: テスト一式、psalm、pint が通ることを確認する**

- [ ] **Step 5: README と CHANGELOG を書く**

  `packages/codes/README.md` は core と同じ構成で、次を書く：
  - 非公式であること、インストール（`composer require jmaxml/codes`）
  - 使い方：市町村コードから細分区域と都道府県を引く例（Task 3 の東京の値）と、「震度速報は細分区域単位なので、市町村を細分区域に直して照合する」使いどころ
  - 収録している表（Task 2 の 6 種と件数）、版（`EarthquakeCodes::version()`）、政令指定都市は区単位であること、表に無いコードは `null` か空配列になること
  - 更新の手順（資料を差し替えて `composer generate`）、出典表記
  
  ルートの README のパッケージ表に `jmaxml/codes` の行を足し、CHANGELOG の `## Unreleased` に「jmaxml/codes を追加」を足す。

- [ ] **Step 6: コミット**

```bash
git add packages/codes README.md CHANGELOG.md
git commit -m "feat(codes): add EarthquakeCodes lookups for areas, cities and stations"
```
