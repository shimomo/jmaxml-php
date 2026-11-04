# 後回しにした minor の対応（2 回目） 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** PR #6 の最終レビューで後回しにした minor 5 件を片付ける。

**Architecture:** 1 件につき 1 タスク。どれも既存の設計の中での小さな修正で、新しいクラスは作らない。挙動を変えるものには、修正前に失敗するテストを付ける。

**Tech Stack:** 既存と同じ（PHP ^8.4、PHPUnit、Psalm errorLevel 1、Pint）

**Spec:** `docs/superpowers/specs/2026-11-03-jmaxml-php-design.md`

**前提:** PR #6 は main にマージ済み（8e158ff）。作業ブランチは `fix/minors-2`。

## Global Constraints

- Packagist には未公開なので、`StateStore` の引数名の変更は許す。`FeedClient` は位置引数で呼ぶので、引数名の違う既存の実装もそのまま動く
- 生成コードは変わらない。core と codes の drift テスト（`GeneratedCodeIsUpToDateTest` など）がそのまま通ること
- 各タスクの終わりに `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test` がすべて通ること
- テストクラスでは `setUp` / `tearDown` を使わない（Psalm の PropertyNotSetInConstructor を避ける、既存の慣習）
- コミットメッセージの末尾は `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

- 呼び出し側が libxml のエラーを溜めている最中に、壊れたフィードを読む → `FeedException` のメッセージはフィード自身のエラーで、呼び出し側のエラーは残る（Task 3）
- 呼び出し側が libxml のエラーを集めていない → `AtomFeed` を読んだ後（成功でも失敗でも）、internal errors のモードは `false` に戻り、バッファは空（Task 3）
- 時差の境界 → `±14:00` と `+13:59` は読める、`+14:01` と `-14:30` は `ParseException`。公式サンプル 546 件は引き続きすべて読める（Task 1、既存の `ReportTest`）
- 31 番シートの並びが変わる（列が挿入されて種類が D 列からずれる、D 列の見出しにラベルが付く） → 生成が止まる（Task 2）
- 震源に関する情報だけの地震（震度速報を受け取り損ねた、またはやり直し中） → `mayBeAtLeast()` は `false`、`citiesAtLeast()` も一致なし。そのことが README に書いてある（Task 5）

## 対応しないもの（理由）

| 内容 | 理由 |
|---|---|
| 震度のデータが無い地震で `mayBeAtLeast()` を `true` にする | 震度のデータが無いと、`citiesAtLeast()` で誰も選べないので、`true` にしても通知先は増えない。震度速報や震源・震度に関する情報が届けば、地震を作り直したときに正しく判定される。振る舞いは変えず、README と docblock に書く |
| core の `Document` と feed の `AtomFeed` で libxml の扱いを共通化する | `Document` は core の `@internal`。パッケージをまたいで内部クラスを使うと、別々にリリースできなくなる。同じ書き方を feed にも置く |

---

### Task 1: core の時差を ±14:00 までにする

**Files:**
- Modify: `packages/core/src/Internal/Node.php`（`DATE_TIME_PATTERN`）
- Test: `packages/core/tests/Internal/NodeTest.php`（`timezoneOffsetsAreChecked`）

- [ ] **Step 1: 失敗するテストを書く**

`timezoneOffsetsAreChecked` に追加する：

```php
$this->assertSame('p', $this->catchParseException(fn() => Node::toDateTime('2026-11-03T12:00:00+14:01', 'p'))->path);
$this->assertSame('p', $this->catchParseException(fn() => Node::toDateTime('2026-11-03T12:00:00-14:30', 'p'))->path);
$this->assertSame('-14:00', Node::toDateTime('2026-11-03T12:00:00-14:00', 'p')->getTimezone()->getName());
$this->assertSame('+13:59', Node::toDateTime('2026-11-03T12:00:00+13:59', 'p')->getTimezone()->getName());
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit --filter timezoneOffsetsAreChecked packages/core/tests`
Expected: FAIL（`+14:01` で ParseException が出ない）

- [ ] **Step 3: 時差の部分を `(Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))` にする**

core の README（「時差は `-14:00`〜`+14:00`」）はすでにこの範囲を書いているので、直さない。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`（公式サンプルをすべて読む `ReportTest` も含む）
Expected: OK

- [ ] **Step 5: コミット**

```bash
git add packages/core
git commit -m "fix(core): reject time zone offsets beyond ±14:00"
```

---

### Task 2: 31 番シート（津波予報区）の D 列を検査する

D 列には見出しのラベルが無く、値はコードの種類を表す。公式の xlsx（2026-10-19 版）の値は 4 種類だけ：`津波予報区`（65 件）、`津波予報区（領域表現としても使用）`（1 件）、`領域表現（構成する津波予報区…）`（15 件）、`結合表現（構成する津波予報区…）`（17 件）。

**Files:**
- Modify: `tools/codegen/src/Codes/EarthquakeCodeTables.php`（`fromXlsx`、新しい `tsunamiAreasFrom`）
- Test: `tools/codegen/tests/Codes/EarthquakeCodeTablesTest.php`

**Interfaces:**
- Produces: `public static function tsunamiAreasFrom(array $rows): array`
  - `@param list<array<string, string>> $rows`：`dataRows()` を通した 31 番シートのデータ行
  - `@return array<array-key, list<string>>`：code => [name, kana, kind]、コード順

- [ ] **Step 1: 失敗するテストを書く**

```php
#[Test] public function tsunamiAreasNeedAKnownKindInColumnD(): void
// tsunamiAreasFrom([['A' => '891', 'B' => '沖縄県地方', 'C' => 'おきなわけんちほう', 'D' => '領域表現（構成する津波予報区800,801,802）']])
//   === ['891' => ['沖縄県地方', 'おきなわけんちほう', '領域表現（構成する津波予報区800,801,802）']]
// D が 'Okinawa' の行 → LogicException。メッセージに 'Sheet 31' と '891' を含む（列がずれた場合）
// D が無い行 → LogicException

#[Test] public function sheet31HeaderLeavesColumnDUnlabelled(): void
// dataRows([['A' => 'Code', 'B' => 'Name', 'C' => 'ふりがな', 'D' => 'English']], '31', ['A' => 'Code', 'B' => 'Name', 'C' => 'ふりがな', 'D' => ''])
//   → LogicException（メッセージに 'column D'）
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit --filter 'tsunamiAreasNeedAKnownKindInColumnD|sheet31HeaderLeavesColumnDUnlabelled' tools/codegen/tests`
Expected: FAIL（`tsunamiAreasFrom` が無い）。2 つ目は `dataRows` の既存の振る舞いなので、最初から通ってよい

- [ ] **Step 3: `tsunamiAreasFrom()` を実装し、`fromXlsx()` から使う**

- `simpleTable($rows, '31', self::AREA_CODE, ['B', 'C', 'D'])` で読んでから、各行の種類が `/^(津波予報区|領域表現|結合表現)/u` に合うかを確かめる。合わなければ `LogicException('Sheet 31: code ' . $code . ' has an unknown kind "' . $kind . '" in column D')`
- `fromXlsx()` では、31 番シートの `dataRows()` に渡す見出しを `[...$codeNameKana, 'D' => '']` にする（D 列にラベルが付いたら並びが変わったとみなす）

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`
Expected: OK（本物の xlsx を読む既存のテストで、98 件と `891` の値が変わらないことも確かめる。codes の drift テストも通る）

- [ ] **Step 5: コミット**

```bash
git add tools/codegen
git commit -m "fix(codegen): check the kind column of the tsunami forecast area sheet"
```

---

### Task 3: feed の `AtomFeed` が呼び出し側の libxml エラーを消さないようにする

core の `Document::fromString()`（PR #6）と同じ書き方にする：読み込みの前に溜まっているエラーの数を数え、その後ろのエラーだけを見る。バッファを消すのは、呼び出し側がエラーを集めていなかったときだけにする。

**Files:**
- Modify: `packages/feed/src/AtomFeed.php`（`load()`）
- Test: `packages/feed/tests/AtomFeedTest.php`

- [ ] **Step 1: 失敗するテストを書く**

どのテストも `try` / `finally` で、最後に `libxml_clear_errors()` と `libxml_use_internal_errors($previous)` を呼ぶ。

```php
#[Test] public function keepsLibxmlErrorsTheCallerAlreadyHad(): void
// libxml_use_internal_errors(true); (new DOMDocument())->loadXML('<broken'); $before = count(libxml_get_errors())（> 0）
// AtomFeed::fromString(Fixtures/eqvol.xml の中身, FeedType::EarthquakeVolcano->highFrequencyUrl()) が成功
// count(libxml_get_errors()) === $before

#[Test] public function reportsTheFeedsOwnErrorWhenTheCallerHasErrorsPending(): void
// 同じ準備のあと AtomFeed::fromString('<feed xmlns="http://www.w3.org/2005/Atom"><id>x</feed>', $url) → FeedException
// メッセージは 'Opening and ending tag mismatch' を含み、'broken' を含まない

#[Test] public function leavesNoLibxmlErrorsForCallersThatDoNotCollectThem(): void
// libxml_use_internal_errors(false) の状態で、上の壊れたフィード → FeedException
// その後 libxml_use_internal_errors(null) === false、libxml_get_errors() === []
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit --filter 'LibxmlErrors|CallerHasErrorsPending' packages/feed/tests`
Expected: 1 つ目が FAIL（`load()` が最初にバッファを消す）。2 つ目と 3 つ目は今の振る舞いを固定するテストなので、最初から通ってよい

- [ ] **Step 3: `load()` を直す**

`libxml_clear_errors()` の 2 か所をやめる。読み込み前の `count(libxml_get_errors())` を取り、`self::firstError()` には `array_slice(libxml_get_errors(), $alreadyPending)` を渡す。`finally` では `$previous` が `false` のときだけバッファを消す。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`
Expected: OK

- [ ] **Step 5: コミット**

```bash
git add packages/feed
git commit -m "fix(feed): keep the caller's libxml errors when reading a feed"
```

---

### Task 4: 既読のキーを「電文の URL」と呼ぶ

PR #6 で、既読のキーを `Entry::$id` から `Entry::$url`（エントリのリンク）に変えた。名前と設計書がまだ id のままなので、そろえる。振る舞いは変わらない（`FeedClientPollTest::identityAndDownloadUseTheLink` がすでに固定している）。

**Files:**
- Modify: `packages/feed/src/StateStore.php`、`packages/feed/src/InMemoryStateStore.php`、`packages/feed/src/FeedClient.php`（`poll()` の `foreach ($seen as $entryId)`）、`packages/feed/tests/Support/FailingOnceStateStore.php`、`packages/feed/README.md`（「StateStore の実装」のコード）
- Modify: `docs/superpowers/specs/2026-11-03-jmaxml-php-design.md`（5.2 の「新着の判定」）

- [ ] **Step 1: 引数名 `$entryId` を `$url` に変える**

`StateStore` の docblock に「既読は電文の URL（`Entry::$url`、エントリのリンク）で記録する」と書く。

- [ ] **Step 2: 設計書 5.2 を直す**

「新着の判定」を次のようにする：「エントリのリンク（電文 XML の URL）で、既に処理したかを判定する。気象庁のフィードでは `id` も同じ URL だが、ダウンロードする URL と既読のキーをそろえるためにリンクを使う。処理済みの記録は利用者が永続化できるよう、インターフェース（`StateStore`）にする。メモリ実装を同梱する。」

- [ ] **Step 3: 確かめる**

Run: `grep -rn entryId packages docs/superpowers/specs`
Expected: 何も出ない

Run: `vendor/bin/phpunit` と `vendor/bin/psalm --no-progress`
Expected: OK、No errors found

- [ ] **Step 4: コミット**

```bash
git add packages/feed docs/superpowers/specs
git commit -m "docs(feed): call the seen key the telegram URL"
```

---

### Task 5: 震度のデータが無い地震での `mayBeAtLeast()` を書く

**Files:**
- Modify: `packages/earthquake/src/EarthquakeEvent.php`（`mayBeAtLeast()` の docblock）、`packages/earthquake/README.md`（「地域ごとの震度と取消の状況」の注意）
- Test: `packages/earthquake/tests/EarthquakeEventTest.php`

- [ ] **Step 1: 今の振る舞いを固定するテストを書く**

```php
#[Test] public function mayBeAtLeastIsFalseWithoutIntensityData(): void
// 32-35_04_03_240613_VXSE52.xml だけ → stage === EventStage::WithHypocenter、maxIntensity === null、
//   mayBeAtLeast(SeismicIntensity::One) === false、citiesAtLeast(SeismicIntensity::One, ['4520100'])->matches === []
// 32-39_05_01_100831_VXSE53.xml（遠地地震）だけ → mayBeAtLeast(SeismicIntensity::One) === false
```

Run: `vendor/bin/phpunit --filter mayBeAtLeastIsFalseWithoutIntensityData packages/earthquake/tests`
Expected: PASS（振る舞いは変えない）

- [ ] **Step 2: docblock と README に書く**

- docblock：「震度のデータが 1 つも無い地震（震源に関する情報だけ、遠地地震）では false。区別するには maxIntensity === null を見る」
- README の注意に 1 行足す：「震度のデータが無い地震では、`mayBeAtLeast()` は `false`、`citiesAtLeast()` も一致なしです。遠地地震のほか、震源に関する情報だけを受け取ったときもそうなります。気象庁は震源に関する情報を震度 3 以上で発表するので、震度速報を受け取り損ねただけかもしれません。震度速報や震源・震度に関する情報が届いたら、地震を作り直して判定し直してください」

- [ ] **Step 3: 確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 4: コミット**

```bash
git add packages/earthquake
git commit -m "docs(earthquake): explain mayBeAtLeast() for events without intensity data"
```
