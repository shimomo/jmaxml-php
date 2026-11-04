# jmaxml/earthquake 実装計画（設計書の jmaxml/seismology）

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 地震の電文（震度速報 VXSE51・震源に関する情報 VXSE52・震源・震度に関する情報 VXSE53）を地震イベントにまとめ、「指定した市町村のうち、震度 X 以上（未入電を含む）だったものはどれか」「取り消されたか」を安全に判定できる `jmaxml/earthquake` を作る。

**Architecture:** core の `Report` の列から、純粋な値オブジェクト `EarthquakeEvent` を組み立てる（状態は持たない。永続化は利用者が電文を保存し、そこから組み立て直す）。情報の単位（電文の種類ごと）で最新のものを選び、取消を反映する。市町村の判定は、震源・震度に関する情報があれば市町村単位で、震度速報しか無い（または震度速報のほうが新しい）細分区域は codes で市町村に展開して行う。

**Tech Stack:** PHP ^8.4、jmaxml/core、jmaxml/codes。PHPUnit / Psalm（errorLevel 1）/ Pint は既存と同じ

**Spec:** `docs/superpowers/specs/2026-11-03-jmaxml-php-design.md`（5.4 節、2 節のアンチゴール）

**前提:** core・codes・feed は main にマージ済み。作業ブランチは `feat/earthquake`。

## 名前の変更（この計画での判断）

設計書では `jmaxml/seismology` としていたが、core の生成クラスがすでに `JmaXml\Seismology\`（地震の内容部）を使っているため、**パッケージ名を `jmaxml/earthquake`、名前空間を `JmaXml\Earthquake\`** にする。用途（地震イベント）とも合い、core の型との取り違えも防げる。設計書の 4 節の表は、この計画の Task 1 で直す。

## Global Constraints

- Packagist 名は `jmaxml/earthquake`、名前空間は `JmaXml\Earthquake\`。`description` は `"Unofficial earthquake event aggregation for JMA (Japan Meteorological Agency) XML"`
- 実行時の依存は php、jmaxml/core、jmaxml/codes だけ
- EventID が別の地震は 1 つにまとめない。関連しそうなものは「関連の可能性あり」として返すだけにする（設計書 5.4 の決定）
- 「震度５弱以上未入電」は捨てず、「5 弱以上の可能性あり」として判定に含める
- `Serial` は順序の判定に使わない。順序は `Head/ReportDateTime`、同じなら `Control/DateTime` で決める
- すべての PHP ファイルは `declare(strict_types=1);`、クラスは `final`、`@author shimomo`

## 調査済みの事実（公式サンプルと core の型）

- 3 種類の電文の管理部の `Title` は、`震度速報`（VXSE51）、`震源に関する情報`（VXSE52）、`震源・震度に関する情報`（VXSE53）。同じ地震は同じ `Head/EventID` を持つが、**編集官署（`Control/EditorialOffice`）は電文の種類で違うことがある**（VXSE51 は大阪管区気象台、VXSE53 は気象庁本庁など）。そこでイベントのキーは「`Control/Status` + `Head/EventID`」、情報の単位は「イベント + `Control/Title`」とする
- core の型（`JmaXml\Seismology\`）：`Body::$earthquake`（list）、`Body::$intensity?->observation`（`IntensityDetail`：`maxInt`、`pref`）、`IntensityPref`（`name`・`code`・`maxInt`・`condition`・`area`）、`IntensityArea`（同じく `city`）、`IntensityCity`（`name`・`code`・`maxInt`・`condition`）。`Earthquake`（`originTime`・`arrivalTime`・`hypocenter?->area`（`name`・`code->value`・`coordinate[0]->points()`）・`magnitude[0]`（`value`・`description`））。`MaxInt` は文字列（core が 1 行の値を trim 済み）
- 「未入電」は `IntensityCity::$condition === '震度５弱以上未入電'` で表れ、そのとき `maxInt` は null（`32-35_06_04_240613_VXSE53.xml` の金ケ崎町 `0338100`（細分区域 213）と大蔵村 `0636500`（241））
- 公式サンプルの流れ（ファイル名、括弧は Head/ReportDateTime）：
  - `32-35_08_*`：EventID が 2 つ。地震 A `20100705065511` は VXSE51 だけ（01 06:57 最大 3、03 06:58 最大 4、05 06:59 最大 4）。地震 B `20100705065610` は VXSE51（02、04）→ VXSE52（06、震源 岩手県沖 `286`、M6.3、`Ｍ６．３`）→ VXSE53（07 07:02、08 07:08、最大 4）。A の VXSE51 の `TargetDateTime` は 06:55、B の VXSE51 は 06:56。どちらも細分区域 211・212 などが震度 4
  - 地震 A の最終（05）の細分区域：211・212・220・222 が 4、200・202・203・210・213・221・230・231・233・240・242・250 が 3
  - 地震 B の最終（08）の市町村：陸前高田市 `0321000`（細分区域 211）と大槌町 `0346100` が 4、大船渡市 `0320300` が 3
  - `32-35_06_*`（EventID `20080614084350`）：VXSE52 01（08:46）→ VXSE52 **取消** 02（08:51）、VXSE53 03（08:47、未入電 3）→ 04（08:53、未入電 2）→ 05（09:01、未入電 1）→ VXSE53 **取消** 06（09:06）。最後には両方とも取り消される
  - `32-35_04_*`（地震 B' `20100125161517`）：VXSE51 02（16:18）→ VXSE52 03 → VXSE53 04（16:19）→ **VXSE51 05（16:20、細分区域 762 が 3 から 4 に上がった）** → VXSE53 06（16:25）。04 の VXSE53 では宮崎市 `4520100`（細分区域 762）は震度 3
  - 東北地方太平洋沖地震：`32-39_11_01_120615_VXSE51.xml`（最大 6+）、`32-39_11_05_240613_VXSE53.xml`（最大 7）
  - 訓練：`32-35_01_02_240613_VXSE52.xml` と `32-35_01_03_240613_VXSE53.xml`（`Status` が `訓練`、EventID `20091001134500`）
  - 遠地地震：`32-39_05_*` の VXSE53 は震度の観測を持たない（南米西部）
- codes：`EarthquakeCodes::city($code)?->areaCode`、`EarthquakeCodes::citiesInArea($areaCode)`。細分区域 212 には盛岡市 `0320100` など 12 市町村

## Review Focus

1. **取消のあとに古い情報で判定すること**：情報の単位の最新が取消なら、その単位の古い電文を判定に使わないこと。すべての単位が取り消されたらイベントは「取消」 → Task 2 の `fullyCancelledEventMatchesNothing`、`partiallyCancelledEventUsesTheRemainingReport`
2. **未入電の市町村の取りこぼし**：「震度５弱以上未入電」の市町村は、どの基準の震度でも「可能性あり」として返すこと → Task 3 の `notYetReceivedCitiesMatchAnyThreshold`
3. **震源・震度の情報より新しい震度速報**：あとから届いた震度速報で上がった細分区域を見落とさないこと → Task 3 の `newerShindoSokuhoRaisesAreasAfterTheDetailedReport`
4. **表に無い市町村コード**：登録された市町村コードが codes に無いとき、黙って「該当なし」にせず、呼び出し側に分かる形で返すこと → Task 3 の `unknownCityCodesAreReported`
5. **電文の届く順序**：電文がどの順に渡されても、同じイベントになること → Task 2 の `orderOfReportsDoesNotMatter`

---

## ファイル構成

```
jmaxml-php/
├── composer.json                         Modify: replace / autoload
├── docs/superpowers/specs/2026-11-03-jmaxml-php-design.md   Modify: パッケージ名の変更を反映
└── packages/earthquake/
    ├── composer.json / LICENSE / README.md / .gitattributes
    ├── src/
    │   ├── SeismicIntensity.php          震度の enum
    │   ├── IntensityReading.php          地域ごとの最大震度と未入電
    │   ├── Hypocenter.php                震源の要約
    │   ├── EventStage.php
    │   ├── EarthquakeEvent.php           1 つの地震
    │   ├── EarthquakeEvents.php          電文の列をイベントに分ける
    │   ├── CityMatch.php / CityMatches.php / MatchBasis.php
    └── tests/
        ├── SeismicIntensityTest.php
        ├── IntensityReadingTest.php
        ├── EarthquakeEventTest.php
        ├── CityMatchingTest.php
        └── Support/Samples.php           公式サンプルを Report にして返す
```

---

### Task 1: パッケージの骨組み、震度と地域ごとの読み取り

**Files:**
- Create: `packages/earthquake/{composer.json,LICENSE,README.md,.gitattributes}`（README は 1 段落だけ。中身は Task 4）
- Modify: `composer.json`（ルート）、設計書（4 節の表と 5.4 節の見出しの `jmaxml/seismology` を `jmaxml/earthquake`（名前空間 `JmaXml\Earthquake\`）に直し、理由を 1 行添える）
- Create: `packages/earthquake/src/{SeismicIntensity,IntensityReading}.php`
- Test: `packages/earthquake/tests/{SeismicIntensityTest,IntensityReadingTest}.php`

**Interfaces:**
- Produces:

```php
namespace JmaXml\Earthquake;

enum SeismicIntensity: string
{
    case One = '1'; case Two = '2'; case Three = '3'; case Four = '4';
    case FiveLower = '5-'; case FiveUpper = '5+'; case SixLower = '6-'; case SixUpper = '6+'; case Seven = '7';

    public static function fromValue(?string $value): ?self;  // 前後の空白を除いて tryFrom。読めなければ null
    public function rank(): int;                              // One=1 … Seven=9
    public function isAtLeast(self $threshold): bool;
    public function label(): string;                          // '震度1' … '震度5弱' '震度5強' '震度6弱' '震度6強' '震度7'
}

final readonly class IntensityReading
{
    public const string NOT_YET_RECEIVED = '震度５弱以上未入電';

    public function __construct(
        public string $code,
        public string $name,
        public ?SeismicIntensity $maxIntensity,
        public bool $notYetReceived,   // 震度５弱以上と推定されるが、観測値が届いていない
    ) {}
    public static function from(string $code, string $name, ?string $maxInt, ?string $condition): self;  // condition に '未入電' を含めば notYetReceived
    public function isAtLeast(SeismicIntensity $threshold): bool;     // 確定した震度で判定
    public function mayBeAtLeast(SeismicIntensity $threshold): bool;  // isAtLeast または notYetReceived
}
```

- [ ] **Step 1: パッケージの骨組みを作る**

  `packages/earthquake/composer.json` は feed と同じ形で、`require` は `php ^8.4`、`jmaxml/core ^0.1.0`、`jmaxml/codes ^0.1.0`。ルートの `composer.json` に `replace` の `jmaxml/earthquake`、autoload `JmaXml\\Earthquake\\` → `packages/earthquake/src/`、autoload-dev `JmaXml\\Earthquake\\Tests\\` → `packages/earthquake/tests/`。設計書を直す。`composer dump-autoload` と `composer normalize --dry-run` が通ること。

- [ ] **Step 2: 失敗するテストを書く**

```php
// SeismicIntensityTest
#[Test] public function readsJmaValues(): void
// fromValue('4') === Four、fromValue('4 ') === Four、fromValue('5-') === FiveLower、fromValue('7') === Seven
// fromValue(null)、fromValue('')、fromValue('震度５弱以上未入電')、fromValue('8') はすべて null
#[Test] public function comparesByStrength(): void
// FiveUpper->isAtLeast(FiveLower) true、FiveLower->isAtLeast(FiveUpper) false、Four->isAtLeast(Four) true、Seven->rank() === 9
#[Test] public function labelsInJapanese(): void   // FiveLower->label() === '震度5弱'、SixUpper->label() === '震度6強'、Three->label() === '震度3'

// IntensityReadingTest
#[Test] public function readsConfirmedIntensity(): void
// from('0321000', '陸前高田市', '4', null)：maxIntensity Four、notYetReceived false、isAtLeast(Four) true、isAtLeast(FiveLower) false、mayBeAtLeast(FiveLower) false
#[Test] public function treatsNotYetReceivedAsPossiblyAnyIntensity(): void
// from('0338100', '金ケ崎町', null, '震度５弱以上未入電')：maxIntensity null、notYetReceived true、isAtLeast(One) false、mayBeAtLeast(Seven) true
```

- [ ] **Step 3: テストが失敗することを確認する**（`vendor/bin/phpunit packages/earthquake/tests`）

- [ ] **Step 4: 実装する**

- [ ] **Step 5: テスト一式、psalm、pint、composer normalize が通ることを確認する**

- [ ] **Step 6: コミット**

```bash
git add composer.json docs/superpowers/specs packages/earthquake
git commit -m "feat(earthquake): add seismic intensity and per-region readings"
```

---

### Task 2: 地震イベントへのまとめ

**Files:**
- Create: `packages/earthquake/src/{Hypocenter,EventStage,EarthquakeEvent,EarthquakeEvents}.php`
- Create: `packages/earthquake/tests/Support/Samples.php`
- Test: `packages/earthquake/tests/EarthquakeEventTest.php`

**Interfaces:**
- Consumes: Task 1 の `SeismicIntensity`・`IntensityReading`、core の `JmaXml\Report` と `JmaXml\Seismology\*`、`JmaXml\Geo\Point`
- Produces:

```php
namespace JmaXml\Earthquake;

enum EventStage
{
    case IntensityOnly;               // 震度速報だけ（震源の情報なし）
    case WithHypocenter;              // 震源に関する情報あり、震源・震度に関する情報なし
    case WithHypocenterAndIntensity;  // 震源・震度に関する情報あり
    case Cancelled;                   // すべての情報の単位が取り消された
}

final readonly class Hypocenter
{
    public function __construct(public string $name, public string $code, public ?Point $point, public ?float $magnitude, public ?string $magnitudeDescription) {}
}

final readonly class EarthquakeEvent
{
    public const string SHINDO_SOKUHO = '震度速報';
    public const string HYPOCENTER = '震源に関する情報';
    public const string HYPOCENTER_AND_INTENSITY = '震源・震度に関する情報';

    /**
     * @param array<string, IntensityReading> $areas 細分区域コード => 読み取り（最新の有効な VXSE53 に震度があればそれ、無ければ最新の有効な VXSE51）
     * @param array<string, IntensityReading> $cities 市町村コード => 読み取り（最新の有効な VXSE53。無ければ空）
     * @param array<string, IntensityReading> $newerShindoSokuhoAreas 最新の有効な VXSE51 が、最新の有効な VXSE53 より新しいときの、その VXSE51 の細分区域（無ければ空）
     */
    public function __construct(
        public string $status,
        public string $eventId,
        public EventStage $stage,
        public ?DateTimeImmutable $detectedAt,   // 最新の有効な VXSE51 の Head/TargetDateTime、無ければ震源の originTime、それも無ければ arrivalTime
        public ?DateTimeImmutable $originTime,
        public ?Hypocenter $hypocenter,          // 最新の有効な VXSE53、無ければ VXSE52 の earthquake[0]
        public ?SeismicIntensity $maxIntensity,  // 最新の有効な VXSE53 の観測の最大震度、無ければ VXSE51。新しい VXSE51 のほうが強ければそちら
        public DateTimeImmutable $lastReportedAt,
        public array $areas,
        public array $cities,
        public array $newerShindoSokuhoAreas,
    ) {}

    /** @param list<Report> $reports 同じ Status と EventID の地震の電文（順不同） */
    public static function fromReports(array $reports): self;   // 空、キーの混在、3 種類以外の電文は \InvalidArgumentException

    public function isCancelled(): bool;   // $stage === EventStage::Cancelled
}

final class EarthquakeEvents
{
    /**
     * @param iterable<Report> $reports
     * @return list<EarthquakeEvent> 地震の電文だけを Status + EventID で分けたもの（3 種類以外の電文は無視）。detectedAt の古い順（null は最後）
     */
    public static function group(iterable $reports): array;
    public static function isEarthquakeReport(Report $report): bool;  // Title が 3 種類のいずれかで Body が Seismology\Body
}
```

  まとめ方の規則：
  - 情報の単位は `Control/Title`。単位ごとに、`Head/ReportDateTime`、同じなら `Control/DateTime` が最も新しい電文を「最新」とする。`InfoType` が「訂正」でも同じ扱い（最新が置き換わるだけ）
  - 最新が `取消` の単位は「無効」。有効な単位が 1 つも無ければ `Cancelled`
  - 震度（`areas`・`cities`・`maxIntensity`）は、有効な VXSE53 に `intensity?->observation` があればそれから、無ければ有効な VXSE51 から読む。地域ごとに `IntensityReading::from(code, name, maxInt, condition)`
  - 震源の `point` は `coordinate[0]?->points()[0]`。座標が無い、または `points()` が `\InvalidArgumentException` を投げたら null（イベントの組み立ては止めない）

- [ ] **Step 1: `Samples` ヘルパーを作る**

  `Samples::reports(string ...$fileNames): list<Report>`（`JmaXml\Tests\Support\Fixtures::sample()` で読んで `Report::fromFile`）。

- [ ] **Step 2: 失敗するテストを書く**

```php
#[Test] public function separatesTwoEarthquakesWithDifferentEventIds(): void
// $events = EarthquakeEvents::group(Samples::reports('32-35_08_01_100915_VXSE51.xml', … '32-35_08_08_240613_VXSE53.xml'))（8 件）
// count 2。[0] は地震 A：eventId '20100705065511'、stage IntensityOnly、hypocenter null、maxIntensity Four、areas['211']->maxIntensity Four、areas['200']->maxIntensity Three、cities []、detectedAt 06:55+09:00
// [1] は地震 B：eventId '20100705065610'、stage WithHypocenterAndIntensity、hypocenter->name '岩手県沖'、code '286'、magnitude 6.3、magnitudeDescription 'Ｍ６．３'、point->latitude 39.6、
//   originTime->format(DATE_ATOM) '2010-07-05T06:55:00+09:00'、maxIntensity Four、cities['0321000']->maxIntensity Four、cities['0320300']->maxIntensity Three、newerShindoSokuhoAreas []

#[Test] public function usesTheLatestReportOfEachKind(): void
// 地震 A の 3 件のうち 01（最大 3）だけ → maxIntensity Three。3 件すべて → Four、lastReportedAt 06:59+09:00

#[Test] public function correctionReplacesTheLatestReport(): void
// 公式サンプルに VXSE5x の訂正は無いので、32-35_08_08_240613_VXSE53.xml の文字列の <InfoType>発表</InfoType> を <InfoType>訂正</InfoType> に、
// '<City><Name>陸前高田市</Name><Code>0321000</Code><MaxInt>4</MaxInt>' を '…<MaxInt>5-</MaxInt>' に書き換えて Report::fromString する（どちらの文字列もファイル内に 1 か所だけ）
// 地震 B の 07 と、その書き換えた 08 → cities['0321000']->maxIntensity FiveLower、stage WithHypocenterAndIntensity

#[Test] public function orderOfReportsDoesNotMatter(): void
// 地震 B の 6 件を逆順で fromReports → 正順と == で等しい

#[Test] public function partiallyCancelledEventUsesTheRemainingReport(): void
// 32-35_06 の 01, 02（VXSE52 取消）, 03 → stage WithHypocenterAndIntensity、hypocenter->name '岩手県内陸南部'（VXSE53 から）

#[Test] public function fullyCancelledEventMatchesNothing(): void
// 32-35_06 の 01〜06 → stage Cancelled、isCancelled() true、areas []、cities []、maxIntensity null

#[Test] public function keepsDrillsApartFromRealEvents(): void
// 32-35_01_02_240613_VXSE52.xml と 32-35_01_03_240613_VXSE53.xml → 1 件、status '訓練'

#[Test] public function readsTheStrongestShaking(): void
// 32-39_11_01_120615_VXSE51.xml と 32-39_11_05_240613_VXSE53.xml → maxIntensity Seven

#[Test] public function distantEarthquakeHasNoIntensity(): void
// 32-39_05_01_100831_VXSE53.xml → stage WithHypocenterAndIntensity、maxIntensity null、areas []、hypocenter->name '南米西部'

#[Test] public function rejectsMixedOrForeignReports(): void
// fromReports([]) / 地震 A と B を混ぜる / 32-39_11_02_250206_VTSE41.xml を入れる → いずれも \InvalidArgumentException
// group() は VTSE41 を無視する（地震 A の 3 件 + VTSE41 → 1 件）
```

- [ ] **Step 3: テストが失敗することを確認する**

- [ ] **Step 4: 実装する**

- [ ] **Step 5: テスト一式、psalm、pint が通ることを確認する**

- [ ] **Step 6: コミット**

```bash
git add packages/earthquake
git commit -m "feat(earthquake): group earthquake telegrams into events"
```

---

### Task 3: 市町村の判定と、関連しそうな地震

**Files:**
- Create: `packages/earthquake/src/{CityMatch,CityMatches,MatchBasis}.php`
- Modify: `packages/earthquake/src/EarthquakeEvent.php`
- Test: `packages/earthquake/tests/CityMatchingTest.php`

**Interfaces:**
- Consumes: Task 2 の `EarthquakeEvent`、codes の `EarthquakeCodes::city()`
- Produces:

```php
namespace JmaXml\Earthquake;

enum MatchBasis
{
    case City;   // 震源・震度に関する情報の市町村の震度
    case Area;   // 震度速報の細分区域の震度を、その細分区域の市町村に当てはめた
}

final readonly class CityMatch
{
    public function __construct(
        public string $cityCode,
        public ?SeismicIntensity $intensity,   // 市町村の震度、または細分区域の最大震度
        public bool $notYetReceived,
        public MatchBasis $basis,
    ) {}
}

final readonly class CityMatches
{
    /**
     * @param list<CityMatch> $matches 指定した順
     * @param list<string> $unknownCityCodes codes に無く、細分区域に直せなかった市町村コード（照合できていない）
     */
    public function __construct(public array $matches, public array $unknownCityCodes) {}
}

// EarthquakeEvent に追加
/** @param list<string> $cityCodes */
public function citiesAtLeast(SeismicIntensity $threshold, array $cityCodes): CityMatches;
public function isPossiblyRelatedTo(self $other, int $windowSeconds = 300): bool;
```

  `citiesAtLeast` の規則：
  - `Cancelled` なら、何も一致しない（`unknownCityCodes` も空）
  - 市町村ごとに、まず `cities`（VXSE53）を見る。`mayBeAtLeast($threshold)` なら `MatchBasis::City` で一致
  - VXSE53 で一致しなかった市町村、または VXSE53 が無い場合は、`EarthquakeCodes::city($code)?->areaCode` で細分区域に直し、`newerShindoSokuhoAreas`（VXSE53 があるとき）または `areas`（VXSE53 が無いとき）を見る。`mayBeAtLeast` なら `MatchBasis::Area` で一致
  - codes に無い市町村コードは、細分区域による判定が必要になったときに `unknownCityCodes` に入れる（VXSE53 の市町村として一致したものは入れない）

  `isPossiblyRelatedTo` の規則：別のイベントで、`status` が同じで、両方の `detectedAt` があってその差が `$windowSeconds` 以内で、震度 3 以上（または未入電）の細分区域を 1 つ以上共有していれば true。取り消されたイベントは false。

- [ ] **Step 1: 失敗するテストを書く**

```php
#[Test] public function expandsShindoSokuhoAreasToCities(): void
// 地震 A（32-35_08_01・03・05）で citiesAtLeast(Four, ['0320100', '0320300', '0110100'])
// → matches は 0320100（盛岡市、細分区域 212）と 0320300（大船渡市、211）の 2 件、どちらも basis Area・intensity Four。unknownCityCodes []

#[Test] public function usesCityIntensitiesFromTheDetailedReport(): void
// 地震 B（32-35_08_02・04・06・07・08）で citiesAtLeast(Four, ['0321000', '0320300'])
// → matches は 0321000 の 1 件（basis City、Four）。大船渡市は震度 3 なので含まない

#[Test] public function notYetReceivedCitiesMatchAnyThreshold(): void
// 32-35_06_01 と 06_04 で citiesAtLeast(Seven, ['0338100']) → 1 件、notYetReceived true、intensity null、basis City

#[Test] public function newerShindoSokuhoRaisesAreasAfterTheDetailedReport(): void
// 32-35_04_02・03・04・05 で citiesAtLeast(Four, ['4520100']) → 1 件（宮崎市）、basis Area、intensity Four
// 32-35_04_02・03・04（05 なし）では 0 件（VXSE53 では震度 3）

#[Test] public function unknownCityCodesAreReported(): void
// 地震 A で citiesAtLeast(Three, ['9999999', '0320100']) → matches は 0320100 だけ、unknownCityCodes === ['9999999']

#[Test] public function cancelledEventMatchesNothing(): void
// 32-35_06_01〜06 で citiesAtLeast(One, ['0338100']) → matches []、unknownCityCodes []

#[Test] public function relatesTheTwoEarthquakesOfTheSameShaking(): void
// 地震 A と B（32-35_08_*）：A->isPossiblyRelatedTo(B) true、B->isPossiblyRelatedTo(A) true、A->isPossiblyRelatedTo(A) false
// isPossiblyRelatedTo(B, 30) は false（06:55 と 06:56 で 60 秒離れている）
// 地震 A と 32-39_11 の地震 → false
```

- [ ] **Step 2: テストが失敗することを確認する**

- [ ] **Step 3: 実装する**

- [ ] **Step 4: テスト一式、psalm、pint が通ることを確認する**

- [ ] **Step 5: コミット**

```bash
git add packages/earthquake
git commit -m "feat(earthquake): match registered cities against an event"
```

---

### Task 4: ドキュメント

**Files:**
- Modify: `packages/earthquake/README.md`、`README.md`（ルートのパッケージ表）、`CHANGELOG.md`

- [ ] **Step 1: README と CHANGELOG を書く**

  `packages/earthquake/README.md` は他のパッケージと同じ構成で：
  - 非公式であること、インストール
  - feed と組み合わせた使い方：受け取った電文（`$telegram->report()` と、保存しておいた同じイベントの電文）から `EarthquakeEvent::fromReports()` を作り、`citiesAtLeast(SeismicIntensity::FiveLower, $registeredCityCodes)` で安否確認の対象を決める例。`unknownCityCodes` と `notYetReceived` の扱い、`isCancelled()` で取消を知らせる例
  - 振る舞い：情報の単位と最新の決め方、取消、未入電、震度速報の展開、新しい震度速報、EventID が別の地震はまとめないこと（`isPossiblyRelatedTo` で知らせるだけ）
  - 注意：イベントは状態を持たないので、利用者が電文を保存して組み立て直すこと。訓練は別のイベントになること。非公式であること、出典
  
  ルートの README のパッケージ表に `jmaxml/earthquake` を足し、CHANGELOG に「jmaxml/earthquake を追加」を足す。

- [ ] **Step 2: README の例を php -r で実行して、書いたとおりの値になることを確認する**

- [ ] **Step 3: テスト一式、psalm、pint、composer normalize が通ることを確認する**

- [ ] **Step 4: コミット**

```bash
git add packages/earthquake README.md CHANGELOG.md
git commit -m "docs(earthquake): document event aggregation and city matching"
```
