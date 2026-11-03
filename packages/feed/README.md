# jmaxml/feed

気象庁防災情報XMLの公開 Atom フィードをポーリングし、まだ処理していない電文だけを返す **非公式** PHP ライブラリです。気象庁とは関係ありません。

An **unofficial** poller for the public Atom feeds of JMA (Japan Meteorological Agency) disaster information XML. It returns only telegrams it has not returned before, filtered by data type and operation status, and always tells you when telegrams may have been missed. Not affiliated with JMA.

- HTTP は PSR-18/17、時刻は PSR-20 に依存します。HTTP クライアントは好きなものを使えます
- 電文の管理部とヘッダ部は [`jmaxml/core`](../core) の `Envelope` で読みます
- ループやスケジューラーは持ちません。`poll()` を自分で呼んでください

## インストール

```bash
composer require jmaxml/feed symfony/http-client nyholm/psr7
```

`symfony/http-client` と `nyholm/psr7` は一例です。PSR-18 クライアントと PSR-17 ファクトリなら何でも構いません。PHP 8.4 以上が必要です。

## 使い方

```php
use JmaXml\Feed\FeedClient;
use JmaXml\Feed\FeedType;
use JmaXml\Feed\Subscription;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\Psr18Client;

$factory = new Psr17Factory();
$client = new FeedClient(
    new Psr18Client(null, $factory, $factory),
    $factory,
    $store,   // 自分で実装した StateStore（下記）
    $clock,   // PSR-20 の ClockInterface
    'my-app/1.0 (contact: you@example.com)',
);

// 地震火山フィードから、震度速報・震源に関する情報・震源・震度に関する情報だけを購読する
$subscription = new Subscription(FeedType::EarthquakeVolcano, ['VXSE51', 'VXSE52', 'VXSE53']);

// 60 秒以上の間隔で呼ぶ（cron やキューのワーカーなど）
$result = $client->poll($subscription);

foreach ($result->telegrams as $telegram) {
    echo $telegram->envelope->head->title, PHP_EOL;   // 震度速報 など
    $report = $telegram->report();                     // Body まで読むとき
}

if ($result->gap !== null && !$result->gap->covered) {
    // $result->gap->since 以降の電文が、まだ取りこぼされている可能性がある。
    // 次回以降の poll() で長期フィードから自動で追いつく。通知や監視に回す
}

foreach ($result->failures as $failure) {
    // $failure->willRetry が true なら次回やり直す。false は直らない失敗（ログに残す）
}
```

## 振る舞い

| 状況 | 振る舞い |
|---|---|
| 初回 | 長期フィードと高頻度フィードの全エントリを既読にして、電文は返しません（`$result->primed === true`）。7 日分の過去の電文を新着として返さないためです |
| 通常 | 高頻度フィードを条件付き GET で取り、新着の電文を古い順に返します |
| 前回の成功から 8 分以上 | 抜けとして扱い、長期フィードも取って追いつきます（`$result->gap`）。長期フィードは毎時更新なので、まだ追いつけないときは `covered === false` を返し、追いつくまで次回以降も長期フィードを取ります |
| 前回の成功から 8 日以上 | 既読の記録が切れているので、初回と同じく取り直します（`primed === true`、`gap->covered === false`） |
| 運用種別 | 既定では「通常」だけを返します。「訓練」「試験」は `new Subscription($feed, $codes, ['通常', '訓練'])` のように明示します |
| データ種類コード | 指定したコードの電文だけをダウンロードします（指定が空なら全部） |
| 電文の取得の失敗 | 5xx や通信エラーは既読にせず、次回やり直します。XML として読めない電文は既読にして `failures` に入れます |
| フィードの取得の失敗 | `FeedException` を投げます。状態は変えないので、次回は抜けとして扱われます |
| 気象庁以外の URL | `https://www.data.jma.go.jp/developer/xml/data/` 以外の電文はダウンロードしません |

- 返した電文は、その時点で既読になります。受け取ったあとの処理（保存や通知）は、利用者の側で確実に行ってください
- 8 分と 8 日は、`FeedClient` のコンストラクタ（`gapThresholdSeconds`・`seenRetentionSeconds`）で変えられます。高頻度フィードは「直近 10 分以上」、長期フィードは「約 7 日分」を掲載しています

## StateStore の実装

既読の記録と、フィードごとの状態（条件付き GET の検証子、最後に成功した時刻、抜け）を保存する場所です。Laravel ならキャッシュや DB の上に実装できます。

```php
interface StateStore
{
    public function isSeen(string $entryId): bool;
    public function markSeen(string $entryId, DateTimeImmutable $expiresAt): void;   // 期限を過ぎたら忘れてよい
    public function load(string $feedUrl): ?FeedState;
    public function save(string $feedUrl, FeedState $state): void;
}
```

- `FeedState::toArray()` は文字列だけの配列を返し、`FeedState::fromArray()` で戻せます。JSON にしてそのまま保存できます
- プロセスの中だけで使う `InMemoryStateStore` も同梱しています（テストや短いスクリプト向け）
- 1 つの StateStore を、複数のプロセスから同時にポーリングしないでください

## アクセスの注意

- `poll()` は 60 秒以上の間隔で呼んでください。高頻度フィードは毎分更新で、CDN のキャッシュも 60 秒です
- 1 日に 10GB を超えるダウンロードがあると、気象庁はそのアクセス元の IP アドレスを遮断します。このライブラリは取得済みの電文を再ダウンロードせず、フィードは条件付き GET で取ります
- `User-Agent` に連絡先を入れることを推奨します（既定値は `jmaxml-feed/0.1`）
- 公開フィードはリアルタイムのサポートが無く、予告なく止まることがあります。迅速・確実な配信が必要な場合は、気象業務支援センターなどの有料配信を検討してください

## 注意

- このライブラリは気象庁の公式ソフトウェアではありません。内容の正確性や即時性は保証しません。実際の防災行動は、気象庁や自治体などの公式の発表に従ってください

## 出典

出典：気象庁ホームページ「気象庁防災情報XMLフォーマット」（https://xml.kishou.go.jp/）

## ライセンス

MIT
