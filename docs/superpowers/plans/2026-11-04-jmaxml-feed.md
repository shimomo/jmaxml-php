# jmaxml/feed 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 気象庁の公開 Atom フィードを行儀よくポーリングし、まだ処理していない電文だけを、データ種類コードと運用種別で絞り込んで返す `jmaxml/feed` を作る。取得の抜けは必ず呼び出し側に分かる形で返す。

**Architecture:** `FeedClient::poll(Subscription)` が 1 回分の取得を行う（ループやスケジューラーは持たない）。HTTP は PSR-18/17、時刻は PSR-20 に依存する。既読の記録・条件付き GET の検証子・抜けの状態は、利用者が永続化できる `StateStore` インターフェースに置く。電文の管理部とヘッダ部は core の `Envelope` で読む。

**Tech Stack:** PHP ^8.4、psr/http-client ^1.0、psr/http-factory ^1.1、psr/http-message ^2.0、psr/clock ^1.0、jmaxml/core。開発時のみ nyholm/psr7 ^1.8（テスト用の PSR-7/17 実装）と symfony/http-client ^8.0（実サーバーに接続するテストだけ）。PHPUnit / Psalm（errorLevel 1）/ Pint は既存と同じ

**Spec:** `docs/superpowers/specs/2026-11-03-jmaxml-php-design.md`（5.2 節、2 節のアンチゴール）

**前提:** core と codes は main にマージ済み。作業ブランチは `feat/feed`。

## Global Constraints

- Packagist 名は `jmaxml/feed`、名前空間は `JmaXml\Feed\`。`description` は `"Unofficial poller for JMA (Japan Meteorological Agency) XML Atom feeds"`
- 実行時の依存は php、jmaxml/core、psr/http-client、psr/http-factory、psr/http-message、psr/clock だけ。具体的な HTTP クライアントは利用者が選ぶ
- 既定では運用種別「通常」の電文だけを返す。「訓練」「試験」は明示したときだけ
- 取得済みの電文を再ダウンロードしない。フィードは条件付き GET（`If-None-Match` / `If-Modified-Since`）で取る
- ループやスケジューラーは持たない。README で「60 秒以上の間隔で呼ぶ」ことを推奨する
- `User-Agent` は設定可能。既定値は `jmaxml-feed/0.1`
- すべての PHP ファイルは `declare(strict_types=1);`、クラスは `final`、`@author shimomo`

## 調査済みの事実（2026-11-04 に実サーバーで確認）

- フィード URL は `https://www.data.jma.go.jp/developer/xml/feed/{regular,extra,eqvol,other}.xml`（高頻度：毎分更新、直近 10 分以上）と `{…}_l.xml`（長期：毎時更新、約 7 日分）
- `ETag` と `Last-Modified` が返り、`If-None-Match` と `If-Modified-Since` のどちらでも 304 になる。`Cache-Control: max-age=60`。gzip にも対応している（PSR-18 クライアントの多くは自動で解凍する。ライブラリでは `Accept-Encoding` を付けない）
- Atom の `entry` は `title`（管理部の Title）、`id`、`updated`、`author/name`、`link`（`type="application/xml"`、`href`）、`content` を持つ。**`id` と `link/@href` は同じ電文の URL**
- 電文 URL のファイル名は `{yyyymmddhhmmss}_{n}_{データ種類コード}_{官署コード 6 桁}.xml`（例：`20261103054436_0_VXSE53_010000.xml`）。先頭の日時は管理部の `DateTime`（UTC）と一致する
- フィードの `updated` は `+09:00`、エントリの `updated` は `Z` で書かれている
- **（最終レビューで追記）** フィードのファイルは内容が変わったときにだけ書き換わる。2026-11-04 17:28Z の観測では、高頻度・長期とも `Last-Modified` が 17:01:13 で、高頻度フィードには 08:00Z〜17:00Z の 25 件が載っていた。つまり高頻度フィードは「直近 10 分以上」を載せ、静かなときはもっと長く残る。この事実を受けて、実装では「304 なら抜けにしない」「抜けの判定と『埋まった』の判定はフィード自身の `<updated>` で行う」「6 日を超えたら取り直す」に改めた
- `packages/feed/tests/Fixtures/eqvol.xml` は 2026-11-04 に取得した実物（25 エントリ）。フィードの `id` は `https://www.data.jma.go.jp/developer/xml/feed/eqvol.xml#short_1793725258`、`updated` は `2026-11-04T02:00:58+09:00`。1 件目は `id` が `https://www.data.jma.go.jp/developer/xml/data/20261103170033_0_VFVO53_010000.xml`、`title` が `降灰予報（定時）`、`updated` が `2026-11-03T17:00:00Z`、`author` が `気象庁`

## 設計の要点（この計画で決めること）

**取得の流れ（`poll()` の 1 回分）**

1. 高頻度フィードの状態（`StateStore::load(高頻度 URL)`）が無い → **初回**。長期フィードと高頻度フィードを取り、全エントリを既読にして、電文は 1 件も返さない（`primed: true`）。初回に 7 日分の過去の電文を「新着」として返すと、古い地震で安否確認が飛ぶため
2. 前回の成功（`fetchedAt`）から `seenRetention`（既定 8 日）より長く経っている → 既読の記録が切れているので、初回と同じく取り直す（`primed: true`、`gap` は「埋まっていない」）
3. それ以外は高頻度フィードを条件付き GET で取る。前回の成功から `gapThreshold`（既定 8 分。高頻度フィードの掲載範囲 10 分より短くして余裕を持たせる）より長く経っているか、前回から埋まっていない抜けが残っていれば、**長期フィードも**条件付き GET で取る
   - 長期フィードの `updated` が `今 − gapThreshold` 以降なら、長期フィードと高頻度フィードで抜けの期間を覆えたので、抜けは「埋まった」
   - そうでなければ（長期フィードは毎時更新なので、穴が残ることがある）抜けは「埋まっていない」。抜けの開始時刻を状態に残し、次回以降も長期フィードを取り続ける
4. 両方のフィードのエントリを `id` で重複なくまとめ、まだ既読でないものを古い順（ファイル名の日時、無ければエントリの `updated`）に処理する
   - データ種類コードの絞り込み（ダウンロードする前）→ 運用種別の絞り込み（ダウンロードして `Envelope` で読んでから）
   - 返すもの、絞り込みで落としたもの、直らない失敗は既読にする。一時的な失敗（電文の取得の HTTP エラー）は既読にせず、次回やり直す
5. フィード自体が取れなければ例外（`FeedException`）。状態は更新しないので、次回は抜けとして扱われる

**既読にするタイミング**：電文を返した時点で既読にする（受け取ったあとの処理は利用者の責任）。README に書く。

**状態の永続化**：`StateStore` を利用者が実装する（Laravel ならキャッシュや DB）。`FeedState` は `toArray()` / `fromArray()` で文字列だけの配列にできる。メモリ実装 `InMemoryStateStore` を同梱する。

## Review Focus

1. **初回や長い停止のあとに、古い電文が新着として大量に返ること**：初回と、既読の記録が切れるほど長い停止のあとは、1 件も返さず `primed` にすること → Task 4 の `firstPollPrimesWithoutReturningTelegrams`、`staleStateIsPrimedAgain`
2. **長期フィードの更新待ちで残る穴**：長期フィードがまだ更新されていないとき、抜けを「埋まった」と言わないこと。埋まるまで次回以降も長期フィードを取ること → Task 4 の `gapStaysOpenUntilTheLongTermFeedCatchesUp`
3. **一時的な失敗で電文を失うこと**：電文の取得が 5xx や通信エラーで失敗したら既読にせず、次回やり直すこと → Task 3 の `transientDownloadFailureIsRetriedNextPoll`
4. **フィードに気象庁以外の URL が混ざること**：`https://www.data.jma.go.jp/` 以外の電文 URL はダウンロードしないこと → Task 3 の `refusesTelegramUrlsOutsideJma`
5. **訓練電文の混入**：既定の購読では「訓練」「試験」の電文を返さないこと → Task 3 の `skipsDrillsByDefault`

---

## ファイル構成

```
jmaxml-php/
├── composer.json                       Modify: replace / autoload / require（psr/*）/ require-dev（nyholm/psr7、symfony/http-client）
├── phpunit.xml.dist                    Modify: live グループを既定で除外
└── packages/feed/
    ├── composer.json / LICENSE / README.md / .gitattributes
    ├── src/
    │   ├── FeedType.php                フィードの種類と URL
    │   ├── TelegramFileName.php        電文 URL のファイル名の分解
    │   ├── Entry.php / AtomFeed.php    Atom の 1 エントリと、フィード全体の読み込み
    │   ├── FeedState.php / StateStore.php / InMemoryStateStore.php
    │   ├── Subscription.php
    │   ├── Telegram.php / Failure.php / Gap.php / PollResult.php
    │   ├── FeedClient.php
    │   └── Exception/FeedException.php
    └── tests/
        ├── Fixtures/eqvol.xml          実物のフィード（取得済み）
        ├── Support/{FakeHttpClient,FrozenClock,AtomFixture}.php
        ├── {FeedTypeTest,TelegramFileNameTest,AtomFeedTest,FeedStateTest,InMemoryStateStoreTest,SubscriptionTest}.php
        ├── FeedClientPollTest.php      通常の取得
        ├── FeedClientGapTest.php       初回・抜け
        └── LiveFeedTest.php            #[Group('live')] 実サーバー
```

---

### Task 1: パッケージの骨組みと Atom の読み込み

**Files:**
- Create: `packages/feed/{composer.json,LICENSE,README.md,.gitattributes}`（README は 1 段落だけ。中身は Task 5）
- Modify: `composer.json`（ルート）
- Create: `packages/feed/src/{FeedType,TelegramFileName,Entry,AtomFeed}.php`、`packages/feed/src/Exception/FeedException.php`
- Test: `packages/feed/tests/{FeedTypeTest,TelegramFileNameTest,AtomFeedTest}.php`（`Fixtures/eqvol.xml` は取得済み）

**Interfaces:**
- Produces:

```php
namespace JmaXml\Feed;

enum FeedType: string
{
    case Regular = 'regular';
    case Extra = 'extra';
    case EarthquakeVolcano = 'eqvol';
    case Other = 'other';

    public function highFrequencyUrl(): string;   // 'https://www.data.jma.go.jp/developer/xml/feed/' . value . '.xml'
    public function longTermUrl(): string;        // … value . '_l.xml'
}

final readonly class TelegramFileName
{
    public function __construct(public DateTimeImmutable $createdAt, public string $sequence, public string $dataTypeCode, public string $officeCode) {}
    public static function fromUrl(string $url): ?self;  // /(\d{14})_(\d+)_([A-Z0-9]+)_(\d{6})\.xml$/、日時は UTC。合わなければ null
}

final readonly class Entry
{
    public function __construct(
        public string $id,            // 電文の URL
        public string $title,
        public DateTimeImmutable $updated,
        public string $author,
        public string $url,           // link/@href
        public string $content,
        public ?TelegramFileName $file,
    ) {}
    public function issuedAt(): DateTimeImmutable;  // $file?->createdAt ?? $updated（並べ替えに使う）
}

final readonly class AtomFeed
{
    /** @param list<Entry> $entries */
    public function __construct(public string $id, public DateTimeImmutable $updated, public array $entries) {}
    public static function fromString(string $xml, string $url): self;  // 読めなければ FeedException（$url と理由を入れる）
}

namespace JmaXml\Feed\Exception;
final class FeedException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $url, public readonly ?int $status = null, ?\Throwable $previous = null);
}
```

  `AtomFeed::fromString` の規則：DOM で読み（`LIBXML_NONET`、libxml のエラーは溜めて PHP の warning を出さない）、DOCTYPE を含む文書・ルートが Atom の `feed` でない文書・`entry` の `id` / `updated` / `link/@href` が無いものは `FeedException`。`title`・`author/name`・`content` が無ければ空文字列。日時は core の `JmaXml\Internal\Node::toDateTime()` と同じ形式（W3C の dateTime）を受け付ける。**core の `@internal` クラスは使わず**、同じ検査を feed 内に書く。

- [ ] **Step 1: パッケージの骨組みを作る**

  `packages/feed/composer.json`：codes と同じ形で、`require` は `php ^8.4`、`jmaxml/core ^0.1.0`、`psr/clock ^1.0`、`psr/http-client ^1.0`、`psr/http-factory ^1.1`、`psr/http-message ^2.0`。`require-dev` は phpunit、psalm、`nyholm/psr7 ^1.8`、`symfony/http-client ^8.0`。`suggest` に `symfony/http-client` と `guzzlehttp/guzzle`（PSR-18 クライアントとして）。ルートの `composer.json` には `replace` に `jmaxml/feed`、`require` に psr の 4 つ、`require-dev` に nyholm/psr7 と symfony/http-client、autoload に `JmaXml\\Feed\\` → `packages/feed/src/`、autoload-dev に `JmaXml\\Feed\\Tests\\` → `packages/feed/tests/`。`composer update` と `composer normalize --dry-run` が通ること。

- [ ] **Step 2: 失敗するテストを書く**

```php
// FeedTypeTest
#[Test] public function buildsFeedUrls(): void
// FeedType::EarthquakeVolcano->highFrequencyUrl() === 'https://www.data.jma.go.jp/developer/xml/feed/eqvol.xml'
// FeedType::Extra->longTermUrl() === 'https://www.data.jma.go.jp/developer/xml/feed/extra_l.xml'

// TelegramFileNameTest
#[Test] public function parsesTelegramUrls(): void
// fromUrl('https://www.data.jma.go.jp/developer/xml/data/20261103054436_0_VXSE53_010000.xml')：
//   createdAt->format(DATE_ATOM) === '2026-11-03T05:44:36+00:00'、sequence '0'、dataTypeCode 'VXSE53'、officeCode '010000'
// fromUrl('https://example.com/feed.xml') === null、fromUrl('…/20261303054436_0_VXSE53_010000.xml')（13 月）=== null

// AtomFeedTest
#[Test] public function readsTheRealEqvolFeed(): void
// $feed = AtomFeed::fromString(file_get_contents(__DIR__ . '/Fixtures/eqvol.xml'), FeedType::EarthquakeVolcano->highFrequencyUrl());
// $feed->id === 'https://www.data.jma.go.jp/developer/xml/feed/eqvol.xml#short_1793725258'
// $feed->updated->format(DATE_ATOM) === '2026-11-04T02:00:58+09:00'、count($feed->entries) === 25
// entries[0]：id と url が '…/20261103170033_0_VFVO53_010000.xml'、title '降灰予報（定時）'、author '気象庁'、
//   updated->format(DATE_ATOM) === '2026-11-03T17:00:00+00:00'、file?->dataTypeCode === 'VFVO53'
// 全エントリで $entry->id === $entry->url

#[Test] #[DataProvider('invalidFeeds')] public function rejectsInvalidFeeds(string $xml): void
// FeedException、$e->url はフィードの URL。呼び出しの前後で error_get_last() が変わらない
// yield 'empty' => ['']; 'not xml' => ['<feed'];
// 'doctype' => ['<?xml version="1.0"?><!DOCTYPE feed [<!ENTITY a "x">]><feed xmlns="http://www.w3.org/2005/Atom"/>'];
// 'not atom' => ['<?xml version="1.0"?><Report xmlns="http://xml.kishou.go.jp/jmaxml1/"/>'];
// 'entry without link' => [id と updated だけある entry を 1 件持つ feed]
```

- [ ] **Step 3: テストが失敗することを確認する**（`vendor/bin/phpunit packages/feed/tests`）

- [ ] **Step 4: 実装する**

- [ ] **Step 5: テスト一式、psalm、pint、composer normalize が通ることを確認する**

- [ ] **Step 6: コミット**

```bash
git add composer.json packages/feed
git commit -m "feat(feed): read JMA Atom feeds"
```

---

### Task 2: 状態の保存と購読条件

**Files:**
- Create: `packages/feed/src/{FeedState,StateStore,InMemoryStateStore,Subscription}.php`
- Create: `packages/feed/tests/Support/FrozenClock.php`
- Test: `packages/feed/tests/{FeedStateTest,InMemoryStateStoreTest,SubscriptionTest}.php`

**Interfaces:**
- Consumes: Task 1 の `Entry`、`FeedType`、`TelegramFileName`
- Produces:

```php
namespace JmaXml\Feed;

final readonly class FeedState
{
    public function __construct(
        public ?string $etag,
        public ?string $lastModified,
        public ?DateTimeImmutable $feedUpdatedAt,   // 最後に読めたフィードの <updated>
        public DateTimeImmutable $fetchedAt,        // 最後に取得に成功した時刻（200 か 304）
        public ?DateTimeImmutable $gapSince = null, // 埋まっていない抜けの開始時刻（高頻度フィードの状態だけで使う）
        /** @var list<string> */
        public array $retryUrls = [],              // 一時的な失敗で、次回やり直す電文の URL（高頻度フィードの状態だけで使う）
    ) {}
    /** @return array{etag: ?string, lastModified: ?string, feedUpdatedAt: ?string, fetchedAt: string, gapSince: ?string, retryUrls: list<string>} 日時は DATE_ATOM */
    public function toArray(): array;
    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self;  // 形が違えば \InvalidArgumentException
}

interface StateStore
{
    public function isSeen(string $entryId): bool;
    public function markSeen(string $entryId, DateTimeImmutable $expiresAt): void;
    public function load(string $feedUrl): ?FeedState;
    public function save(string $feedUrl, FeedState $state): void;
}

final class InMemoryStateStore implements StateStore
{
    public function __construct(private readonly ClockInterface $clock) {}  // 期限切れの既読は isSeen で false。markSeen のたびに期限切れを掃除する
}

final readonly class Subscription
{
    /**
     * @param list<string> $dataTypeCodes 空なら全種類（例 ['VXSE51', 'VXSE52', 'VXSE53']）
     * @param list<string> $statuses 返す運用種別（管理部の Status）
     */
    public function __construct(public FeedType $feed, public array $dataTypeCodes = [], public array $statuses = ['通常']) {}
    public function wantsDataType(Entry $entry): bool;  // コードの指定が空なら true。指定があってファイル名が読めなければ false
    public function wantsStatus(string $status): bool;
}

// tests/Support
namespace JmaXml\Feed\Tests\Support;
final class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable;
    public function advance(string $modifier): void;  // 例 '+9 minutes'
}
```

- [ ] **Step 1: 失敗するテストを書く**

```php
// FeedStateTest
#[Test] public function roundTripsThroughArrays(): void
// 全項目あり（retryUrls 2 件を含む）・null と空配列の 2 通りで、FeedState::fromArray($state->toArray()) == $state
// toArray()['fetchedAt'] === '2026-11-03T12:00:00+00:00'
#[Test] public function rejectsMalformedArrays(): void   // fromArray(['fetchedAt' => 'yesterday']) と fromArray([]) は \InvalidArgumentException

// InMemoryStateStoreTest
#[Test] public function forgetsSeenEntriesAfterTheyExpire(): void
// clock 2026-11-03T12:00:00Z、markSeen('a', +8 days) → isSeen('a') true、isSeen('b') false
// clock->advance('+8 days +1 second') → isSeen('a') false
#[Test] public function storesFeedStatePerUrl(): void   // save/load は URL ごと、未保存なら null

// SubscriptionTest
#[Test] public function filtersByDataTypeCodeAndStatus(): void
// 既定の Subscription(FeedType::EarthquakeVolcano)：VXSE53 のエントリも、ファイル名の読めないエントリも wantsDataType true。wantsStatus('通常') true、('訓練') false、('試験') false
// Subscription(…, ['VXSE51', 'VXSE53'])：VXSE53 → true、VFVO53 → false、ファイル名の読めないエントリ → false
// Subscription(…, [], ['通常', '訓練'])：wantsStatus('訓練') true
```

- [ ] **Step 2: テストが失敗することを確認する**

- [ ] **Step 3: 実装する**

- [ ] **Step 4: テスト一式、psalm、pint が通ることを確認する**

- [ ] **Step 5: コミット**

```bash
git add packages/feed
git commit -m "feat(feed): add feed state, state store and subscriptions"
```

---

### Task 3: 通常の取得（FeedClient の本体）

**Files:**
- Create: `packages/feed/src/{FeedClient,Telegram,Failure,Gap,PollResult}.php`
- Create: `packages/feed/tests/Support/{FakeHttpClient,AtomFixture}.php`
- Test: `packages/feed/tests/FeedClientPollTest.php`

**Interfaces:**
- Consumes: Task 1・2 の全部、core の `JmaXml\Envelope::fromString()`（`ParseException` を投げる）と `JmaXml\Report::fromString()`
- Produces:

```php
namespace JmaXml\Feed;

final readonly class Telegram
{
    public function __construct(public Entry $entry, public string $xml, public Envelope $envelope) {}
    public function report(): Report;  // Report::fromString($this->xml)。呼ぶたびに読む
}

final readonly class Failure
{
    public function __construct(public Entry $entry, public string $reason, public bool $willRetry, public ?\Throwable $previous = null) {}
}

final readonly class Gap
{
    public function __construct(public DateTimeImmutable $since, public bool $covered) {}
}

final readonly class PollResult
{
    /**
     * @param list<Telegram> $telegrams 新着で条件に合う電文（古い順）
     * @param list<Failure> $failures
     */
    public function __construct(
        public array $telegrams,
        public array $failures,
        public bool $notModified,      // 高頻度フィードが 304
        public bool $primed,           // 初回・取り直し。電文は返していない
        public ?Gap $gap,              // この回に扱った抜け（無ければ null）
    ) {}
}

final class FeedClient
{
    public const string DEFAULT_USER_AGENT = 'jmaxml-feed/0.1';
    public const string TELEGRAM_URL_PREFIX = 'https://www.data.jma.go.jp/developer/xml/data/';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StateStore $store,
        private readonly ClockInterface $clock,
        private readonly string $userAgent = self::DEFAULT_USER_AGENT,
        private readonly int $gapThresholdSeconds = 480,
        private readonly int $seenRetentionSeconds = 691200,   // 8 日
    ) {}

    public function poll(Subscription $subscription): PollResult;
}

// tests/Support
final class FakeHttpClient implements ClientInterface
{
    /** URL ごとに、返す応答（ResponseInterface）か投げる例外（ClientExceptionInterface）を順に積む。積んでいない URL へのリクエストはテストの失敗にする */
    public function queue(string $url, ResponseInterface|ClientExceptionInterface ...$responses): void;
    /** @return list<RequestInterface> */
    public function requests(): array;
    /** @return list<RequestInterface> */
    public function requestsTo(string $url): array;
}
final class AtomFixture
{
    /** @param list<array{url: string, title?: string, updated?: string}> $entries */
    public static function feed(string $updated, array $entries): string;  // 実物と同じ形の Atom を作る
}
```

  この Task では、高頻度フィードの状態がすでにあり、抜けも無い場合（`fetchedAt` が 1 分前）だけを扱う。初回と抜けは Task 4。`poll()` の処理の順序は「設計の要点」の 3〜5 のとおり。加えて：
  - すべてのリクエストに `User-Agent` を付ける。フィードには、保存してある検証子があれば `If-None-Match`（ETag）と `If-Modified-Since`（Last-Modified）を付ける
  - フィードの応答が 200 と 304 以外、または通信例外なら `FeedException`（`status` に HTTP ステータス）。状態は保存しない
  - 電文 URL が `TELEGRAM_URL_PREFIX` で始まらなければ、ダウンロードせず `Failure(willRetry: false)` にして既読にする
  - 電文の取得が 200 以外・通信例外なら `Failure(willRetry: true)`、既読にせず、その URL を高頻度フィードの `FeedState::$retryUrls` に残す。304 ではフィードの本文が無いので、次回の `poll()` は、フィードの応答にかかわらず `retryUrls` も処理の対象に加える。フィードにも同じエントリがあればフィードの情報を使い、無ければ URL から `Entry` を作る（`title`・`author`・`content` は空、`updated` はファイル名の日時、読めなければ今）。成功または直らない失敗になったら一覧から外す
  - 電文が `Envelope` で読めなければ（`ParseException`）`Failure(willRetry: false)`、既読にする
  - 既読の期限は `今 + seenRetentionSeconds`
  - 成功したら高頻度フィードの `FeedState`（応答の ETag・Last-Modified、フィードの updated、fetchedAt = 今、gapSince = null）を保存する。304 なら検証子と feedUpdatedAt は前回の値を引き継ぐ

  テストで使う電文は公式サンプル（`JmaXml\Tests\Support\Fixtures::sample()`）：`32-35_08_05_100915_VXSE51.xml`（震度速報、通常）、`32-35_01_03_240613_VXSE53.xml`（震源・震度、**訓練**）。フィードに載せる URL は `TELEGRAM_URL_PREFIX` + ファイル名の形（例 `…/20261103115900_0_VXSE51_270000.xml`）にする。時計は `2026-11-03T12:00:00Z` から始める。

- [ ] **Step 1: 失敗するテストを書く**（`FeedClientPollTest`）

```php
#[Test] public function returnsNewTelegramsOldestFirst(): void
// 状態：高頻度 URL に FeedState(etag '"e1"', lastModified 'Tue, 03 Nov 2026 11:58:00 GMT', feedUpdatedAt 11:58Z, fetchedAt 11:59Z)
// 高頻度フィードに 3 件：既読の A、新着 B（11:59:30 の VXSE51）、新着 C（11:58:10 の VXSE51）。フィードでの並びは A, B, C
// → telegrams の entry->id は [C, B]、envelope->control->title は '震度速報'、telegrams[0]->report()->head->eventID === '20100705065511'
// → B と C は既読になる。A のダウンロードは起きない
// → 高頻度フィードへのリクエストに If-None-Match '"e1"' と If-Modified-Since が付き、すべてのリクエストの User-Agent が 'jmaxml-feed/0.1'
// → 保存された FeedState の fetchedAt は 12:00Z、etag は応答の値

#[Test] public function notModifiedReturnsNothingAndKeepsValidators(): void
// 304 → notModified true、telegrams []、保存された FeedState の etag は前回の値のまま、fetchedAt は 12:00Z

#[Test] public function filtersByDataTypeBeforeDownloading(): void
// Subscription(…, ['VXSE53']) で VXSE51 のエントリ → telegrams []、その電文 URL へのリクエストは 0 件、エントリは既読

#[Test] public function skipsDrillsByDefault(): void
// 訓練の VXSE53 サンプル → 既定の Subscription では telegrams []、既読
// Subscription(…, [], ['通常', '訓練']) では 1 件返る

#[Test] public function transientDownloadFailureIsRetriedNextPoll(): void
// 電文 URL が 503 → failures[0]->willRetry true、既読にならない、保存された FeedState の retryUrls にその URL
// 時計を 1 分進め、高頻度フィードは 304、電文 URL は 200 → 2 回目の poll で telegrams に 1 件、retryUrls は空、既読になる

#[Test] public function refusesTelegramUrlsOutsideJma(): void
// エントリの URL が 'https://example.com/20261103115900_0_VXSE51_270000.xml' → そこへのリクエストは 0 件、failures[0]->willRetry false、既読

#[Test] public function unreadableTelegramIsReportedAndNotRetried(): void
// 電文 URL が 200 で本文 'not xml' → failures[0]->willRetry false、previous は ParseException、既読

#[Test] public function feedErrorThrowsAndKeepsState(): void
// 高頻度フィードが 503 → FeedException（status 503、url は高頻度 URL）。保存済みの FeedState は変わらない
// 高頻度フィードで通信例外 → FeedException（status null）
```

- [ ] **Step 2: テストが失敗することを確認する**

- [ ] **Step 3: `FeedClient` と結果のクラスを実装する**

- [ ] **Step 4: テスト一式、psalm、pint が通ることを確認する**

- [ ] **Step 5: コミット**

```bash
git add packages/feed
git commit -m "feat(feed): poll the high-frequency feed and download new telegrams"
```

---

### Task 4: 初回と抜けの扱い

**Files:**
- Modify: `packages/feed/src/FeedClient.php`
- Test: `packages/feed/tests/FeedClientGapTest.php`

**Interfaces:**
- Consumes: Task 3 の `FeedClient`、`PollResult`、`Gap`、`FeedState`
- Produces: 公開 API の追加なし（`poll()` の振る舞いの追加）

  「設計の要点」の 1〜3 を実装する。長期フィードの状態（検証子と feedUpdatedAt）は長期 URL をキーに別に保存する。長期フィードが 304 のときは、保存してある feedUpdatedAt で「埋まったか」を判定する。

- [ ] **Step 1: 失敗するテストを書く**（時計は 2026-11-03T12:00:00Z、gapThreshold 480 秒）

```php
#[Test] public function firstPollPrimesWithoutReturningTelegrams(): void
// 状態なし。長期フィードに 2 件、高頻度フィードに 2 件（1 件は長期と同じ）
// → primed true、telegrams []、gap null、電文 URL へのリクエスト 0 件、3 件とも既読
// → 高頻度と長期の両方の FeedState が保存される（fetchedAt 12:00Z）
// 続けて時計を 1 分進め、高頻度フィードに新着 1 件 → 2 回目の poll はその 1 件だけ返す（primed false）

#[Test] public function shortGapIsCoveredByTheLongTermFeed(): void
// 状態：高頻度の fetchedAt 11:51Z（9 分前）
// 長期フィード（updated 11:59Z）に、抜けの間の新着 X（11:55Z）、高頻度フィードに新着 Y（11:59:30Z）
// → telegrams は [X, Y]、gap == new Gap(11:51Z, covered: true)、保存された gapSince は null

#[Test] public function gapStaysOpenUntilTheLongTermFeedCatchesUp(): void
// 状態：fetchedAt 11:20Z（40 分前）。長期フィード updated 11:00Z
// → gap == new Gap(11:20Z, covered: false)、保存された gapSince は 11:20Z
// 1 分後：高頻度は 304、長期は 304（feedUpdatedAt 11:00Z のまま）→ gap は (11:20Z, false) のまま、長期 URL へ条件付きリクエストが出ている
// さらに 1 分後：長期フィードが 200（updated 12:01Z、抜けの間の新着 Z を含む）→ telegrams に Z、gap == (11:20Z, true)、gapSince は null

#[Test] public function noGapMeansNoLongTermRequest(): void
// fetchedAt 1 分前、gapSince null → 長期 URL へのリクエストは 0 件、gap null

#[Test] public function staleStateIsPrimedAgain(): void
// fetchedAt 9 日前 → primed true、telegrams []、gap == (9 日前, covered: false)、長期と高頻度の全エントリが既読
```

- [ ] **Step 2: テストが失敗することを確認する**

- [ ] **Step 3: 実装する**

- [ ] **Step 4: テスト一式、psalm、pint が通ることを確認する**

- [ ] **Step 5: コミット**

```bash
git add packages/feed
git commit -m "feat(feed): prime on first poll and catch up gaps from the long-term feed"
```

---

### Task 5: 実サーバーでの確認とドキュメント

**Files:**
- Create: `packages/feed/tests/LiveFeedTest.php`
- Modify: `phpunit.xml.dist`（`<groups><exclude><group>live</group></exclude></groups>`）
- Modify: `packages/feed/README.md`、`README.md`（ルートのパッケージ表）、`CHANGELOG.md`

- [ ] **Step 1: 実サーバーのテストを書く**

```php
#[Test] #[Group('live')] public function pollsTheRealEarthquakeVolcanoFeed(): void
// symfony/http-client の Psr18Client と nyholm の Psr17Factory、InMemoryStateStore、実時計（DateTimeImmutable('now') を返す小さな ClockInterface）
// 1 回目の poll → primed true、telegrams []
// 2 回目の poll（すぐ）→ primed false、notModified true または telegrams は 0 件以上、例外なし
```

- [ ] **Step 2: 既定のテストでは live が走らないこと、`vendor/bin/phpunit --group live` では通ることを確認する**

  Expected：`vendor/bin/phpunit` の件数に LiveFeedTest が含まれない。`--group live` は OK（ネットワークが無い環境では失敗してよい。CI では走らせない）

- [ ] **Step 3: README と CHANGELOG を書く**

  `packages/feed/README.md` は core・codes と同じ構成で：
  - 非公式であること、インストール（`composer require jmaxml/feed` と、PSR-18 クライアントの例として `symfony/http-client`）
  - 使い方：`FeedClient` の組み立て（Psr18Client、Psr17Factory、自前の StateStore、時計）と、震度速報など（`['VXSE51', 'VXSE52', 'VXSE53']`）を購読して 60 秒ごとに `poll()` する例。`$result->gap` が埋まっていないときと `$result->failures` の扱い
  - 振る舞い：初回は返さない（primed）、運用種別は既定で「通常」だけ、返した電文は既読になる、一時的な失敗は次回やり直す、抜けの判定（高頻度フィード 10 分・長期フィード 7 日・毎時更新）
  - `StateStore` の実装の注意（`FeedState::toArray()` で保存できる、既読には期限がある、ポーリングを複数のプロセスから同時にしない）
  - アクセスの注意：60 秒以上の間隔、1 日 10GB を超えると遮断されること、User-Agent に連絡先を入れることを推奨、公開フィードは予告なく止まることがある
  - 出典表記
  
  ルートの README のパッケージ表に `jmaxml/feed` の行を足し、CHANGELOG に「jmaxml/feed を追加」を足す。

- [ ] **Step 4: テスト一式、psalm、pint、composer normalize が通ることを確認する**

- [ ] **Step 5: コミット**

```bash
git add phpunit.xml.dist packages/feed README.md CHANGELOG.md
git commit -m "docs(feed): document polling and add a live feed check"
```
