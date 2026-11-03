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

// 60〜120 秒の間隔で呼ぶ（常駐ワーカーやスケジューラーなど）
$result = $client->poll($subscription);

foreach ($result->telegrams as $telegram) {
    echo $telegram->envelope->head->title, PHP_EOL;   // 震度速報 など
    $report = $telegram->report();                     // Body まで読むとき

    // 抜けから追いついた電文は、数時間〜数日前のものであることがある。
    // 通知する前に、発表時刻（$telegram->envelope->head->reportDateTime）を確かめる
}

if ($result->gap !== null && !$result->gap->covered) {
    // $result->gap->since 以降の電文が、まだ取りこぼされている可能性がある。
    // 次回以降の poll() で長期フィードから自動で追いつく。通知や監視に回す
}

foreach ($result->failures as $failure) {
    // $failure->willRetry が true なら次回やり直す。false は直らない失敗（ログに残す）
}

foreach ($result->warnings as $warning) {
    // 長期フィードが取れなかった、フィードの壊れたエントリを飛ばした、など。poll() は続行している
}
```

## 振る舞い

| 状況 | 振る舞い |
|---|---|
| 初回 | 長期フィードと高頻度フィードの全エントリを既読にして、電文は返しません（`$result->primed === true`）。7 日分の過去の電文を新着として返さないためです |
| 通常 | 高頻度フィードを条件付き GET で取り、新着の電文を古い順に返します |
| 高頻度フィードが 304 | 前回から何も変わっていないので、どれだけ間が空いていても抜けにはしません |
| 前回の成功から、フィードが 8 分以上先に進んでいた | 抜けとして扱い、長期フィードも取って追いつきます（`$result->gap`）。判定にはフィード自身の `<updated>` を使います。長期フィードが追いつけていないときは `covered === false` を返し、追いつくまで次回以降も長期フィードを取ります |
| 長期フィードが取れない | 例外にせず、高頻度フィードの新着は返します。`gap->covered` は `false` のままで、理由は `warnings` に入ります |
| 前回の成功から 6 日以上 | 長期フィード（約 7 日分）では追いつけないので、初回と同じく取り直します（`primed === true`、`gap->covered === false`）。このときの `gap` は、この 1 回だけ返ります |
| 運用種別 | 既定では「通常」だけを返します。「訓練」「試験」は `new Subscription($feed, $codes, ['通常', '訓練'])` のように明示します |
| データ種類コード | 指定したコードの電文だけをダウンロードします（指定が空なら全部）。ファイル名からコードが読めないエントリは `failures` に入れます |
| 電文の取得の失敗 | 5xx・通信エラー・本文の読み込み中の切断は既読にせず、次回やり直します。XML として読めない電文は既読にして `failures` に入れます |
| フィードの壊れたエントリ | そのエントリだけを飛ばし、`warnings` に入れます |
| フィードの取得の失敗 | 高頻度フィードが取れなければ `FeedException` を投げます。状態は変えないので、次回は同じエントリをもう一度処理します |
| 気象庁以外の URL | `https://www.data.jma.go.jp/developer/xml/data/` 以外の電文はダウンロードしません |

- `covered === true` でも、`willRetry` の失敗が残っていることがあります。それらは次回以降に届きます
- 8 分・6 日・既読の保持期間（8 日）は、`FeedClient` のコンストラクタ（`gapThresholdSeconds`・`catchUpLimitSeconds`・`seenRetentionSeconds`）で変えられます。ただし、高頻度フィードの「直近 10 分以上」と長期フィードの「約 7 日分」を外れる値は `InvalidArgumentException` になります

### 返した電文は既読になる

`poll()` が返した電文は、その時点で既読になっています（at-most-once）。受け取ってから通知を記録するまでの間にプロセスが落ちると、その電文はもう返りません。確実に処理したいときは、`StateStore` をアプリと同じデータベースの上に実装し、`poll()` と「通知の記録（outbox）」を 1 つのトランザクションで行ってください。例外が出たらロールバックすれば、既読の記録も元に戻ります。

```php
DB::transaction(function () use ($client, $subscription) {
    $result = $client->poll($subscription);   // StateStore は同じ DB に書く

    foreach ($result->telegrams as $telegram) {
        Outbox::create(['xml' => $telegram->xml]);   // 通知はコミット後に outbox から送る
    }
});
```

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
- 既読の記録と `FeedState` は、一緒に保存し、一緒に消してください。**古いものから勝手に消えるキャッシュ（LRU、Redis の maxmemory、`cache:clear` など）は使わないでください**。既読だけが消えると、過去の電文が新着として返り、気象庁から電文を大量に取り直すことになります
- 1 つの StateStore は、1 つのフィードにつき 1 つの `Subscription` で使ってください。欲しいデータ種類コードはすべて 1 つの `Subscription` に入れます。別の `Subscription` で使うと `LogicException` になります（購読を変えるときは、状態を消して初回からやり直すか、別の StateStore を使います）
- プロセスの中だけで使う `InMemoryStateStore` も同梱しています（テストや短いスクリプト向け）
- 1 つの StateStore を、複数のプロセスから同時にポーリングしないでください

## アクセスの注意

- `poll()` は 60〜120 秒の間隔で呼んでください。CDN のキャッシュは 60 秒です。間隔を 8 分以上にすると、フィードが更新されるたびに抜けとして扱われます
- サーバーの時計は NTP などで合わせてください。抜けの判定は、前回の取得時刻（このマシンの時計）とフィードの `<updated>`（気象庁の時計）を比べます
- 1 日に 10GB を超えるダウンロードがあると、気象庁はそのアクセス元の IP アドレスを遮断します。このライブラリは取得済みの電文を再ダウンロードせず、フィードは条件付き GET で取ります
- `User-Agent` に連絡先を入れることを推奨します（既定値は `jmaxml-feed/0.1`）
- 公開フィードはリアルタイムのサポートが無く、予告なく止まることがあります。迅速・確実な配信が必要な場合は、気象業務支援センターなどの有料配信を検討してください

## 例外

- `FeedException`：高頻度フィード（初回は長期フィードも）が取れない・読めないとき
- `LogicException`：StateStore が別の `Subscription` で使われているとき
- `StateStore` の実装が投げた例外は、そのまま外に出ます。そのときに既読にした分があれば、上のトランザクションで戻してください

## 注意

- このライブラリは気象庁の公式ソフトウェアではありません。内容の正確性や即時性は保証しません。実際の防災行動は、気象庁や自治体などの公式の発表に従ってください

## 出典

出典：気象庁ホームページ「気象庁防災情報XMLフォーマット」（https://xml.kishou.go.jp/）

## ライセンス

MIT
