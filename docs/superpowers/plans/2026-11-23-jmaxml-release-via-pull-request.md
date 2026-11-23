# リリースを PR 経由にする 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** main に直接 push せずにリリースできるようにする。`bin/release.sh prepare <version>` で版を上げる PR を作り、マージした後に `bin/release.sh publish <version>` でタグと分割先への公開だけを行う。

**Architecture:** 2026-11-23 に main へルールセットを入れた（PR 必須、squash のみ、署名必須、admin のバイパスは PR 経由だけ）。これで、今の `bin/release.sh` の 2 回の `git push origin main`（`chore: prepare release` と `chore: update CHANGELOG.md`）が通らなくなった。そこで、版の書き換え（CHANGELOG と composer.json 群）をリリース用のブランチでコミットして PR にする `prepare` と、マージ後の main に署名付きタグを打って分割先へ公開する `publish` の 2 つに分ける。手本は re-buji の `bin/release.sh`（版の変更は PR、スクリプトはタグとリリースだけ）。事前確認（`release_preflight`）は、どちらの段階かを引数で受け取り、段階ごとの確認を足す。

**Tech Stack:** POSIX sh、git（subtree）、jq 1.7、composer、gh。テストは PHPUnit（既存の `tests/Release`）

**Spec:** `docs/superpowers/specs/2026-11-03-jmaxml-php-design.md`（4.1「リリース」）

## Global Constraints

- 4 パッケージはいつも同じ版番号で、まとめてリリースする（4.1）
- タグは `x.y.z`（`v` を付けない）で、署名付き（`git tag -s`）。分割先は `shimomo/jmaxml-php-<package>`（4.1）
- タグの push と公開は、実行の直前に作者に確認する（4.1）。`publish` は今と同じく `release_confirm` で `y` を待つ
- main への push は、どの段階でも行わない。版の変更は squash マージで main に入る
- CHANGELOG の版の節は、タグを打つコミットに入っていること（タグ `0.1.1` の CHANGELOG には自分の節が無かった。boatrace-php の fd953a8 と同じ不具合）
- 「## Unreleased」の見出しは残す（版の節はその下に差し込む）。タグの後に main へコミットしないため
- テストは push もタグの公開もしない。一時ディレクトリとローカルの bare リポジトリ（`Sandbox`）だけを使い、`finally` で消す
- テストクラスでは `setUp` / `tearDown` を使わない（既存の慣習）。Psalm errorLevel 1 と Pint を通す
- コミットメッセージの末尾は `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

- 前の使い方のまま `bin/release.sh 0.1.2` と打つ → 何も変えずに使い方を出して止まる（Task 2）
- prepare の PR をマージする前に `publish` を実行する → CHANGELOG に版の節が無い、または相互依存が `^<version>` でないので、タグを打つ前に止まる（Task 1）
- `publish` → origin の main は実行の前後で同じコミットのまま（Task 2）
- 「## Unreleased」の見出しが無い CHANGELOG → `prepare` が見出しを戻してから版の節を書く（boatrace-php の a7548d4 と同じ。Task 2）
- 同じ版で `prepare` を 2 回実行する（リリース用のブランチが origin にもうある）→ 何も変えずに止まる（Task 1）

---

### Task 1: 事前確認を段階ごとにする

**Files:**
- Modify: `bin/lib/preflight.sh`
- Modify: `tests/Release/Support/Sandbox.php`（`repo/` に `CHANGELOG.md` を足す）
- Test: `tests/Release/PreflightTest.php`

**Interfaces:**
- Produces: `release_preflight STEP VERSION`（STEP は `prepare` か `publish`）。今の確認（版の形、main、作業ツリー、タグ、直前のタグより新しい、origin/main と同じ、分割先に届く）に、段階ごとの確認を足して、理由をまとめて 1 回で出す。STEP がほかの値なら、それも理由に入れる
  - `prepare`：`CHANGELOG.md` にまだ `## [VERSION]` の節が無い。ブランチ `release/VERSION` が手元にも origin にも無い
  - `publish`：`CHANGELOG.md` に `## [VERSION]` の節がある。`packages/*/composer.json` の `require` と `require-dev` にある `jmaxml/*` が、すべて `^VERSION`
- Produces: `release_branch VERSION`：リリース用のブランチ名 `release/VERSION` を出力する（Task 2 の `prepare` も使う）
- Produces: `Sandbox` の `repo/` に `CHANGELOG.md`（`# Changelog\n\n## Unreleased\n\n- first\n`）がコミットされ、origin に push されている

- [ ] **Step 1: 失敗するテストを書く**

```php
#[Test] #[DataProvider('steps')]
public function passesOnACleanMainInStepWithOrigin(string $step, string $setup): void
// prepare: setup なし → 0
// publish: setup で CHANGELOG を「## Unreleased」の下に「## [0.1.0] - 2026-11-23」を入れた形にしてコミットし push → 0

// brokenStates は [step, setup, version, reason] にする。今の 8 件は prepare で残す。足すのは次の 5 件
yield 'unknown step' => ['ship', 'true', '0.1.0', 'ship'];
yield 'section already stamped' => ['prepare', <[0.1.0] の節を入れてコミットし push>, '0.1.0', 'publish'];
yield 'release branch on origin' => ['prepare', 'git push -q origin main:refs/heads/release/0.1.0', '0.1.0', 'release/0.1.0'];
yield 'prepare not merged' => ['publish', 'true', '0.1.0', 'prepare'];
yield 'mutual require not bumped' => ['publish', <[0.1.0] の節を入れ、packages/feed/composer.json を {"require": {"jmaxml/core": "^0.0.1"}} にしてコミットし push>, '0.1.0', 'packages/feed/composer.json'];
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Release/PreflightTest.php`
Expected: FAIL（`release_preflight` が段階を受け取らない）

- [ ] **Step 3: `release_preflight STEP VERSION` と `release_branch VERSION` を実装する**

origin のブランチは `git ls-remote --exit-code --heads origin` で見る（タグの確認と同じく、2 は「無い」、それ以外は「読めない」）。相互依存は jq で読むだけにする（`|=` は使わない）。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Release` と `vendor/bin/psalm --no-progress` と `vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add bin/lib/preflight.sh tests/Release
git commit -m "chore: check the prepare and publish steps of a release separately"
```

---

### Task 2: `bin/release.sh` を prepare と publish に分ける

**Files:**
- Modify: `bin/release.sh`、`bin/lib/changelog.sh`
- Modify: `tests/Release/Support/Sandbox.php`（`repo/` にルートの `composer.json`、`fake-bin/` に何もしない `composer` を足す）
- Modify: `README.md`（「リリース」の節）、`docs/superpowers/specs/2026-11-03-jmaxml-php-design.md`（4.1 に追記）
- Test: `tests/Release/ChangelogTest.php`、`tests/Release/ReleaseScriptTest.php`（新規）

**Interfaces:**
- Consumes: `release_preflight STEP VERSION`、`release_branch VERSION`（Task 1）
- Produces: `changelog_release_notes VERSION`（`bin/lib/changelog.sh`）：`## [VERSION]` の見出しの次の行から次の `## ` 見出しの前までを、前後の空行を除いて出力する（PR の本文に使う）
- Produces: `bin/release.sh prepare VERSION`
  1. `release_preflight prepare VERSION`
  2. `release_branch VERSION` のブランチを作って移る
  3. `changelog_ensure_unreleased_heading` のあと、今の手順 1（手書きの Unreleased か、直前のタグ以降の件名）で版の節を書く
  4. `composer_version_release VERSION`
  5. `CHANGELOG.md composer.json packages/*/composer.json` を `chore: prepare release VERSION` としてコミットする
  6. ブランチを origin に push し、`gh pr create --base main --head <branch> --title "chore: prepare release VERSION" --body <本文>` で PR を作る。本文は `changelog_release_notes VERSION` と、マージ後に `bin/release.sh publish VERSION` を実行する旨
  7. main に戻り、次にすることを出す
- Produces: `bin/release.sh publish VERSION`：`release_preflight publish VERSION`、`release_confirm VERSION`、`git tag -s`、タグの push、`package_release_publish` を全パッケージに。main には push しない
- 引数が `prepare|publish VERSION` でなければ、使い方を標準エラーに出して終了コード 1（何も変えない）
- Produces: `Sandbox` の `repo/` に `composer.json`（`{"name": "test/test", "replace": {"jmaxml/core": "self.version", "jmaxml/feed": "self.version"}}`）、`fake-bin/composer`（呼ばれた引数を `composer.log` に書いて 0 で終わる）

- [ ] **Step 1: 失敗するテストを書く**

```php
// ChangelogTest
#[Test] public function releaseNotesAreTheSectionOfTheVersion(): void
// "# Changelog\n\n## Unreleased\n\n## [0.1.1] - 2026-11-23\n\n- a\n- b\n\n## [0.1.0] - 2026-11-05\n\n- c\n"
// changelog_release_notes 0.1.1 → [0, "- a\n- b\n"]、changelog_release_notes 0.1.0 → [0, "- c\n"]

// ReleaseScriptTest（Sandbox で bin/release.sh を絶対パスで実行する）
#[Test] public function prepareOpensAPullRequestAndLeavesMainAlone(): void
// bin/release.sh prepare 0.1.0 → 0
// origin main の SHA は実行の前と同じ。origin に release/0.1.0 があり、その先頭の件名は "chore: prepare release 0.1.0"、
// その CHANGELOG.md に "## [0.1.0] - " と "- first" がある。gh.log に "gh pr create --base main --head release/0.1.0" がある。
// 手元のブランチは main

#[Test] public function prepareRestoresAMissingUnreleasedHeading(): void
// setup で CHANGELOG.md を "# Changelog\n" にしてコミットし push → bin/release.sh prepare 0.1.0 → 0、
// origin の release/0.1.0 の CHANGELOG.md に "## Unreleased" と "## [0.1.0] - " がある

#[Test] public function publishTagsMainWithoutPushingToIt(): void
// setup で prepare の PR がマージされた状態（[0.1.0] の節を入れてコミットし push）を作り、
// printf 'y\n' | bin/release.sh publish 0.1.0 → 0
// origin main の SHA は実行の前と同じで、origin のタグ 0.1.0 の指すコミットと同じ。
// jmaxml-php-core.git と jmaxml-php-feed.git に refs/tags/0.1.0 がある

#[Test] public function refusesTheOldSingleStepUsage(): void
// bin/release.sh 0.1.0 → 1、出力に "usage"。origin にタグもブランチも増えていない（git ls-remote origin の出力が実行の前と同じ）
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Release`
Expected: FAIL（`changelog_release_notes` が無い。`bin/release.sh` が `prepare` と `publish` を知らない）

- [ ] **Step 3: `changelog_release_notes` を足し、`bin/release.sh` を書き直す**

先頭のコメント（使い方と流れ）も 2 段階に合わせて書き直す。手順 6（`chore: update CHANGELOG.md`）は無くなる。

- [ ] **Step 4: README と設計書を直す**

- README「リリース」：2 段階の手順、止まる条件（段階ごと）、失敗したときの再開（prepare の途中：手元の `release/<version>` を消して main に戻る。publish でタグが origin に無い：手元にタグだけあれば消してやり直す。タグが origin にある：残りのパッケージだけ `package_release_publish`）。prepare の PR をマージするまで、ほかの PR をマージしない（件名から作った CHANGELOG とタグの中身がずれるため）
- 設計書 4.1：「（2026-11-23 変更）」として、ルールセットを入れたので 2 段階にしたこと、版の節はタグを打つコミットに入ること

- [ ] **Step 5: 通ることを確かめる**

Run: `vendor/bin/phpunit` と `vendor/bin/psalm --no-progress` と `vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 6: コミット**

```bash
git add bin tests/Release README.md docs/superpowers/specs/2026-11-03-jmaxml-php-design.md
git commit -m "chore: release through a pull request instead of pushing to main"
```
