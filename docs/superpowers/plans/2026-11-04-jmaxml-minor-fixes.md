# 後回しにした minor の対応 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** core・codes・feed・earthquake の最終レビューで後回しにした minor を片付ける。挙動の修正には、修正前に失敗するテストを付ける。

**Architecture:** パッケージごとに 1 タスク。既存の設計は変えず、検査の追加・小さな API の追加・ドキュメントの修正に留める。生成コードに関わるものは `tools/codegen` を直して `composer generate` で再生成する。

**Tech Stack:** 既存と同じ（PHP ^8.4、PHPUnit、Psalm errorLevel 1、Pint）

**Spec:** `docs/superpowers/specs/2026-11-03-jmaxml-php-design.md`

**前提:** 4 パッケージは main にマージ済み、リポジトリは public。作業ブランチは `fix/minors`。

## Global Constraints

- 既存の公開 API は壊さない（追加だけ）。ただし feed の既読のキーを `Entry::$id` から `Entry::$url` に変える（今の気象庁では同じ値なので、保存済みの状態はそのまま使える）
- 生成コードは手で直さず、`composer generate` で再生成する。core の生成コードが変わるのは Task 1 の辞書の修正だけ
- すべての修正はテストで確かめる。ドキュメントだけの修正はテストなし

## 対応しないもの（理由）

| 出どころ | 内容 | 理由 |
|---|---|---|
| core | `type` 属性が無いとき、緯度 1 度未満の度分表記を度と読み違える | 桁数からは区別できない。`type` が付く電文（台風など）では正しく読める。README に書くだけにする |
| core | `points()` の例外にパスが無い | 値オブジェクトから位置が分からない。README に「座標が読めないと `InvalidArgumentException`」と書く |
| earthquake | 細分区域の判定元を `cities !== []` で選んでいる | 震度の観測はあるが市町村が無い VXSE53 では、VXSE53 の細分区域で判定する今の振る舞いのほうが安全側。変えない |
| feed | DB 向けの一括書き込み API | 利用者の実装の問題。必要になったら考える |

---

### Task 1: core と ジェネレーター

**Files:**
- Modify: `tools/codegen/src/Php/{ClassRenderer,ScalarKind}.php`、`tools/codegen/src/Generator.php`、`tools/codegen/bin/generate`、`tools/codegen/tests/GeneratorTest.php`
- `tools/codegen/tests/Generated/Mini/*` の場所は変えない（出力先ディレクトリと基準の名前空間の指定だけを変える）
- Modify: `packages/core/src/Internal/{Node,Document}.php`、`packages/core/src/Geo/Iso6709.php`
- Regenerate: `packages/core/src/**`（docblock が増える）
- Test: `packages/core/tests/Internal/{NodeTest,DocumentTest}.php`、`packages/core/tests/Geo/Iso6709Test.php`、`packages/core/tests/ReportTest.php`、`tools/codegen/tests/{GeneratorTest,GeneratedCodeIsUpToDateTest}.php`

- [ ] **Step 1: 失敗するテストを書く**

```php
// GeneratedCodeIsUpToDateTest に追加
#[Test] public function referencedElementsCarryTheirDictionaryDescription(): void
// packages/core/src/Seismology/Earthquake.php の内容に '@var list<Magnitude> マグニチュード' が含まれる
// （辞書は参照要素を 'jmx_eb:Magnitude' のように接頭辞付きで持っている）

// GeneratorTest に追加
#[Test] public function rejectsClassNamesThatDifferOnlyInCase(): void
// tools/codegen/tests/Fixtures/case/case.xsd（type.FooBar と type.Foobar を持つ最小の XSD）から generate() → \LogicException

// NodeTest に追加
#[Test] public function intsAreRangeChecked(): void
// toInt('99999999999999999999', 'p') は ParseException（桁あふれ）。toInt('256', 'p', 0, 255) は ParseException、toInt('255', 'p', 0, 255) === 255
#[Test] public function timezoneOffsetsAreChecked(): void
// toDateTime('2026-11-03T12:00:00+09:60', 'p') と '…+99:00' は ParseException。'…+14:00' と '…-12:00' は成功

// DocumentTest に追加（いずれも ParseException、path '/'、error_get_last() が変わらない）
// yield 'entity without doctype' => ['<?xml version="1.0"?><Report xmlns="http://xml.kishou.go.jp/jmaxml1/">&undefined;</Report>', 'Malformed XML'];
// billion laughs（実体を 10 段入れ子にした DOCTYPE 付きの文書）は別のテストにし、ParseException・path '/'・0.5 秒未満で終わることだけを確かめる（DOCTYPE で拒否されるか libxml が止めるかは問わない）
// yield 'nul byte' => ["<?xml version=\"1.0\"?><Report xmlns=\"http://xml.kishou.go.jp/jmaxml1/\">\0</Report>", 'Malformed XML'];
// yield 'invalid utf-8' => ["<?xml version=\"1.0\" encoding=\"UTF-8\"?><Report xmlns=\"http://xml.kishou.go.jp/jmaxml1/\">\xff</Report>", 'Malformed XML'];
#[Test] public function keepsLibxmlErrorsTheCallerAlreadyHad(): void
// libxml_use_internal_errors(true); 壊れた XML を loadXML して libxml のエラーを 1 件溜める → Document::fromString(正しい電文) → libxml_get_errors() に元の 1 件が残っている
#[Test] public function unreadableFileDoesNotWarn(): void
// ディレクトリのパスを fromFile に渡す → ParseException、error_get_last() が変わらない

// Iso6709Test に追加
#[Test] public function rejectsOutOfRangeValues(): void
// parse('+91.0+135.0/')、parse('+35.0+181.0/')、parse('+3560.0+13500.0/', '位置（度分）')（60 分）はすべて \InvalidArgumentException

// ReportTest に追加
#[Test] public function ignoresUnknownElementsInsideTheBodyAndForeignNamespaces(): void
// 32-35_08_05_100915_VXSE51.xml の <Intensity> の直後に <Future>x</Future> と <x:Other xmlns:x="urn:x">y</x:Other> を足しても読め、body->intensity->observation->maxInt は '4'
```

- [ ] **Step 2: テストが失敗することを確認する**

- [ ] **Step 3: 実装する**

  - **辞書の参照要素**：`ClassRenderer` の要素の説明は、`forElement(prefix, type, name)` が null で、要素の名前空間が型と違うときに `forElement(prefix, type, prefixFor(要素の名前空間) . ':' . name)` を引く
  - **大文字小文字だけ違うクラス名**：`Generator::generate()` の重複検査を `strtolower($path)` で行う
  - **整数の範囲**：`Node::toInt(string $raw, string $path, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int` は `filter_var(FILTER_VALIDATE_INT)` で桁あふれを検出し、範囲外は `ParseException`。`toNullableInt` も同じ引数を持つ。`ScalarKind` に `UnsignedByte` を足し（`xs:unsignedByte` → `Node::toInt($raw, $path, 0, 255)`）、`Int`（`xs:int`）は `-2147483648`〜`2147483647` を渡す
  - **使っていない対応を消す**：`ScalarKind::fromBase` から、XSD で使われていない型（`double`、`decimal`、`byte`、`short`、`unsignedShort`、`long`、`unsignedInt`、`nonNegativeInteger`、`positiveInteger`、`integer`、`normalizedString`、`gMonthDay`）を消す（出てきたら止まるように）。使っていない `ScalarKind::isNullable()` も消す
  - **時差**：`DATE_TIME_PATTERN` の時差を `Z|[+-](?:0\d|1[0-4]):[0-5]\d` にする
  - **libxml のエラー**：`Document::fromString` は開始時に `libxml_clear_errors()` を呼ばず、開始時点のエラー数より後ろのエラーだけを見る。終了時は、呼び出し前に内部エラーモードでなかったときだけ消す
  - **ファイルの読み込み**：`Document::fromFile` は `set_error_handler` で warning を受け止めてから `file_get_contents` し、失敗は `ParseException`
  - **座標の範囲**：`Iso6709` は緯度の絶対値 90 以下、経度 180 以下、分・秒 60 未満を確かめ、外れたら `\InvalidArgumentException`
  - **ミニ XSD の出力先**：`bin/generate` と `GeneratorTest::miniGenerator()` は、出力先を `tools/codegen/tests/Generated`、基準の名前空間を `JmaXml\Codegen\Tests\Generated` にする（古いファイルを消す範囲をテスト全体に広げない）。生成されるパスは `Mini/*.php` になる

- [ ] **Step 4: `composer generate` を実行して、テスト一式・psalm・pint が通ることを確認する**

  Expected：`Generated 274 files.`、core の生成コードの差分は docblock の追加だけ（`git diff --stat packages/core/src` で行の追加のみ）

- [ ] **Step 5: コミット**（`fix(core): …` と `chore(codegen): …` の 2 つに分けてよい）

---

### Task 2: codes

**Files:**
- Modify: `tools/codegen/src/Codes/EarthquakeCodeTables.php`、`tools/codegen/bin/generate`、`packages/codes/src/EarthquakeCodes.php`、`packages/codes/README.md`、`README.md`
- Test: `tools/codegen/tests/Codes/EarthquakeCodeTablesTest.php`

- [ ] **Step 1: 失敗するテストを書く**

```php
#[Test] public function checksTheHeaderLabels(): void
// dataRows([['A' => 'Code', 'B' => 'ふりがな', 'C' => 'Name'], …], '24', ['A' => 'Code', 'B' => 'Name', 'C' => 'ふりがな']) → \LogicException
// 見出しが期待どおりならデータ行を返す

#[Test] public function simpleTablesRejectMalformedOrDuplicateCodes(): void
// simpleTable([['A' => '11', 'B' => '北海道地方']], '41', '/^\d{3}$/', ['B']) → \LogicException（011 が数値 11 として入った場合）
// simpleTable([['A' => '011', 'B' => 'x'], ['A' => '011', 'B' => 'y']], '41', '/^\d{3}$/', ['B']) → \LogicException
// simpleTable([['A' => '891', 'B' => '沖縄県地方', 'C' => 'おきなわけんちほう', 'D' => '領域表現']], '31', '/^\d{3}$/', ['B', 'C', 'D'])['891'] === ['沖縄県地方', 'おきなわけんちほう', '領域表現']

#[Test] public function requiresCompleteTables(): void
// hierarchyFrom([]) → \LogicException（データ行が無い）
// fromParts(prefectures に '01' と '02'、areas が 01 だけ) → \LogicException（細分区域の無い都道府県）

#[Test] public function duplicateStationMessageNamesTheStation(): void
// 同じ観測点が 2 行 → \LogicException のメッセージに 'station' を含む
```

- [ ] **Step 2: テストが失敗することを確認する**

- [ ] **Step 3: 実装する**

  - `dataRows(array $rows, string $sheet, array $expectedHeader)`：`Code` 行の各列のラベルが `$expectedHeader` と一致しなければ止める。シートごとの期待値は、23：`A=Code, B=Name`、24：`A=Code, B=Name, C=ふりがな, D=Code, E=Name, F=ふりがな, G=Code, H=Name, I=ふりがな`、31：`A=Code, B=Name, C=ふりがな`、41：`A=Code, B=Name`（2026-10-19 版で確認済み）
  - `public static function simpleTable(array $rows, string $sheet, string $pattern, list<string> $columns): array<array-key, list<string>>` を切り出し、シート 23・31・41 の読み込みをこれで行う（1 列目だけ空を許さない）
  - `fromXlsx` は、読み込んだあとに `fromParts(...)`（検証つきのコンストラクタ代わり）を通す：6 つの表がどれも空でないこと、細分区域の都道府県がすべて都道府県の表にあること、どの都道府県にも細分区域が 1 つ以上あること
  - `hierarchyFrom` は空のデータ行を拒否する。行ごとの細分区域・市町村の値は 1 回だけ組み立てる。観測点の重複のメッセージは `duplicate station code …`
  - `bin/generate` は、core の生成・codes の表の読み込み・検証をすべて済ませてから、ファイルを書く
  - `EarthquakeCodes` に `private function __construct() {}`

- [ ] **Step 4: README を直す**

  - `packages/codes/README.md`：更新手順に「件数を確かめるテスト（`EarthquakeCodeTablesTest`・`EarthquakeCodesTest`）は、毎月の更新で件数が変わったら、新しい版の件数に合わせて直す（意図した確認点）」を足す。出典を「[気象庁ホームページ「気象庁防災情報XMLフォーマット」](https://xml.kishou.go.jp/)の個別コード表を加工して作成」と、「データは政府標準利用規約（第2.0版）に従う」に改める
  - ルートの `README.md`：同梱資料の列挙にコード表を足す

- [ ] **Step 5: `composer generate`（Data に差分が出ないこと）、テスト一式、psalm、pint を確認してコミット**

---

### Task 3: feed

**Files:**
- Modify: `packages/feed/src/{FeedClient,FeedState,InMemoryStateStore}.php`、`packages/feed/README.md`
- Test: `packages/feed/tests/{FeedClientPollTest,FeedStateTest,InMemoryStateStoreTest}.php`

- [ ] **Step 1: 失敗するテストを書く**

```php
// FeedClientPollTest に追加
#[Test] public function missingTelegramIsNotRetriedForever(): void
// 電文 URL が 403 → failures[0]->willRetry false、既読、retryUrls は空。404 も同じ。408 と 429 は willRetry true

#[Test] public function unreadableTelegramIsRetriedOnce(): void
// 1 回目：200 で 'not xml' → willRetry true、既読にならない、retryUrls に入る
// 2 回目（1 分後、フィードは 304）：また 'not xml' → willRetry false、既読、retryUrls は空
// （既存の unreadableTelegramIsReportedAndNotRetried は、この 2 段階の振る舞いに合わせて書き換える）

#[Test] public function identityAndDownloadUseTheLink(): void
// エントリの id が 'tag:jma,2026:abc'、link が TELEGRAM_URL_PREFIX + '20261103115930_0_VXSE51_270000.xml' → ダウンロード先は link、既読になるのも link

// FeedStateTest：parseFailedUrls を含めて往復できる

// InMemoryStateStoreTest に追加
#[Test] public function marksManyEntriesQuickly(): void
// 20,000 件の markSeen が 1 秒未満（今の実装は 1 件ごとに全件を掃除するので遅い）。期限切れが消えることは既存のテストで確かめる
```

- [ ] **Step 2: テストが失敗することを確認する**

- [ ] **Step 3: 実装する**

  - 電文の取得の応答が 4xx（408・429 を除く）なら、直らない失敗（`willRetry: false`、既読）にする
  - `FeedState` に `list<string> $parseFailedUrls = []` を足す（`toArray` / `fromArray` も）。読めない電文は、`parseFailedUrls` に無ければ `willRetry: true` にして `retryUrls` と `parseFailedUrls` に入れ、すでにあれば直らない失敗にして両方から外す
  - 既読・候補の重複・`retryUrls` はすべて `Entry::$url` で扱う（`Entry::$id` は情報として残す）
  - `InMemoryStateStore::markSeen` の掃除は、前回の掃除から 1,000 件ごとに 1 回にする

- [ ] **Step 4: README の振る舞いの表を直す**（4xx の扱い、読めない電文は 1 回だけやり直す）

- [ ] **Step 5: テスト一式、psalm、pint を確認してコミット**

---

### Task 4: earthquake

**Files:**
- Modify: `packages/earthquake/src/EarthquakeEvent.php`、`packages/earthquake/README.md`、設計書 5.4
- Test: `packages/earthquake/tests/{EarthquakeEventTest,CityMatchingTest}.php`

**Interfaces（追加）:**

```php
// EarthquakeEvent に追加
public function city(string $code): ?IntensityReading;   // 整数のキーになったコードも文字列で引ける
public function area(string $code): ?IntensityReading;
public function prefecture(string $code): ?IntensityReading;
public function station(string $code): ?IntensityReading;
/** @var array<array-key, IntensityReading> */ public array $prefectures;   // 震度の読み取り元と同じ電文の都道府県
/** @var array<array-key, IntensityReading> */ public array $stations;      // 最新の有効な VXSE53 の震度観測点（無ければ空）
/** @var list<string> */ public array $cancelledKinds;                       // 最新が取消の情報の単位の Control/Title
public function mayBeAtLeast(SeismicIntensity $threshold): bool;            // maxIntensity が基準以上、または未入電の地域がある
```

  新しいプロパティはコンストラクタの末尾に足す（既存の引数の位置は変えない）。

- [ ] **Step 1: 失敗するテストを書く**

```php
// EarthquakeEventTest に追加
#[Test] public function looksUpRegionsByStringCode(): void
// 東北地方太平洋沖地震（32-39_11_01・05）：city('1310100')?->maxIntensity は FiveUpper（キーは整数でも引ける）、area('350') も引ける
// prefecture('13')?->name === '東京都'、station('1310100') は null でない
#[Test] public function exposesPartialCancellation(): void
// 32-35_06 の 01, 02 → cancelledKinds === ['震源に関する情報']。01〜06 → 2 件
#[Test] public function mayBeAtLeastCountsNotYetReceivedRegions(): void
// 32-35_06_01・03・04：maxIntensity は SixUpper、mayBeAtLeast(Seven) は true（未入電の市町村がある）
// 地震 A：mayBeAtLeast(FiveLower) は false
#[Test] public function sameEventIdWithDifferentStatusesStaysApart(): void
// 地震 B の 5 件と、08_08 を訓練に書き換えたもの → group(…, ['通常', '訓練']) は 2 件
#[Test] public function lonelyCancellationIsCancelled(): void   // 32-35_10_01_220510_VXSE51.xml だけ → Cancelled
#[Test] public function sameReportTimeFallsBackToControlDateTime(): void
// 32-35_07_01〜03（ReportDateTime がすべて 05:09）を 6 通りの順で渡しても同じ areas
#[Test] public function rejectsEachKindOfBadInput(): void   // 空・混在・VTSE41 をそれぞれ個別に expectException

// CityMatchingTest に追加
#[Test] public function cancellingOnlyTheDetailedReportFallsBackToShindoSokuho(): void
// 32-35_04_02・03・04 と、04_04 を取消に書き換えたもの（InfoType 取消、ReportDateTime を 16:21 に）→ stage WithHypocenter、cancelledKinds に '震源・震度に関する情報'、宮崎市は basis Area で判定
#[Test] public function detailedReportWithoutIntensityFallsBackToShindoSokuho(): void
// 32-35_08_07 から <Intensity>…</Intensity> を取り除いたものと、地震 B の 02・04 → citiesAtLeast(Four, ['0321000']) は basis Area で一致
#[Test] public function relatesTheTwoEarthquakesOfTheSecondSequence(): void
// 32-35_04_01（地震 A'）と 32-35_04_02〜06（地震 B'）→ isPossiblyRelatedTo true
#[Test] public function relationNeedsTheSameStatusAndLiveEvents(): void
// 地震 A と、訓練に書き換えた地震 B → false。地震 A と取り消された地震 → false
```

- [ ] **Step 2: テストが失敗することを確認する**

- [ ] **Step 3: 実装する**

  - 都道府県の読み取りは、市町村・細分区域と同じ規則で、その下のどれかが未入電なら未入電にする
  - 観測点の読み取りは、`Int` が `震度５弱以上未入電` なら `notYetReceived`

- [ ] **Step 4: ドキュメントを直す**

  - `packages/earthquake/README.md`：地域の表のキーは整数になることがあるので `city()` などで引くこと、`cancelledKinds` と `mayBeAtLeast()` の使いどころ、情報の単位から編集官署を外している理由（同じ種類の電文は同じ官署から出る前提）を書く
  - 設計書 5.4：情報の単位を「Status + EventID + Title」にしたことと、その理由を 1 行足す

- [ ] **Step 5: テスト一式、psalm、pint を確認してコミット**

---

### Task 5: リポジトリ全体の仕上げ

**Files:**
- Create: `.gitattributes`
- Modify: `packages/core/README.md`

- [ ] **Step 1: `.gitattributes` を作る**

```
* text=auto eol=lf
*.xlsx binary
*.zip binary
*.png binary
```

  `git add --renormalize .` のあと `git status` で差分が無いこと（すでに LF のため）を確認する。

- [ ] **Step 2: core の README に足す**：数値の `INF` / `-INF` はそのまま float で返ること、`composer generate` には ext-zip が要ること、座標（`type` 属性が無い度分表記の緯度 1 度未満）の限界と `points()` の例外

- [ ] **Step 3: テスト一式、psalm、pint、composer normalize を確認してコミット**
