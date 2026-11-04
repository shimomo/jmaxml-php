# Packagist への公開（リリースの仕組み） 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `bin/release.sh <version>` の 1 回の実行で、4 パッケージを同じ版番号で分割先リポジトリに公開できるようにする。

**Architecture:** boatrace-php の `bin/release.sh` と `bin/lib/*.sh`（`~/github/shimomo/boatrace-php/bin/`）を写し、名前を jmaxml 用に直す。そのうえで jmaxml 用に 2 つを足す。1 つ目は、手書きの「Unreleased」をそのまま使う CHANGELOG の扱い。2 つ目は、何かを push する前に失敗の原因をつぶす事前確認。シェルの関数は、PHPUnit から一時ディレクトリとローカルの bare リポジトリの上で動かしてテストする。

**Tech Stack:** POSIX sh、git（subtree）、jq 1.7、composer、gh。テストは PHPUnit（既存の構成）

**Spec:** `docs/superpowers/specs/2026-11-03-jmaxml-php-design.md`（4 章と 4.1「リリース」）

## Global Constraints

- 4 パッケージはいつも同じ版番号で、まとめてリリースする（4.1）
- Packagist の名前は `jmaxml/<package>`、分割先は `shimomo/jmaxml-php-<package>`（4 章）
- 最初の版は `0.1.0`（4.1）。`packages/*/composer.json` の `^0.1.0` と `0.1.x-dev` はそのままでよい
- CHANGELOG は、直前のタグ以降のコミットの件名から作る。ただし「Unreleased」に手書きの項目があれば、それをそのまま使う（4.1 の「0.1.0 だけは手書き」を一般化した。私の判断）
- テストは push もタグの公開もしない。一時ディレクトリとローカルの bare リポジトリだけを使い、`finally` で消す
- テストの git リポジトリでは、`commit.gpgsign` と `tag.gpgSign` を `false` にする（作者の設定では署名が有効）
- テストクラスでは `setUp` / `tearDown` を使わない（既存の慣習）。Psalm errorLevel 1 と Pint を通す
- コミットメッセージの末尾は `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

- 初回のリリースで「Unreleased」に手書きの紹介がある → 全コミットの件名を流し込まず、手書きの項目が `0.1.0` の下に入る（Task 2）
- 分割先のリポジトリがまだ 1 つでも無い、または origin にもう同じタグがある → main もタグも push する前に止まる（Task 3）
- リリースの途中で composer.json 群を同期する → 手で整えたルートの `composer.json` の依存と autoload が変わらない（Task 1）
- 手元の main が origin より古い、または未コミットの変更がある → 止まる（Task 3）
- 分割したツリー → 各パッケージの `composer.json` がツリーの直下にあり、`composer validate --strict` が通る（Task 4）

---

### Task 1: boatrace-php のリリースの仕組みを写す

**Files:**
- Create: `bin/release.sh`、`bin/merge.sh`、`bin/lib/changelog.sh`、`bin/lib/composer-merge.sh`、`bin/lib/composer-version.sh`、`bin/lib/package-release.sh`（boatrace-php の同名ファイルを写す。実行権限を付ける）
- Create: `tests/Release/Support/Shell.php`、`tests/Release/ComposerScriptsTest.php`
- Modify: `composer.json`（`autoload-dev` に `"JmaXml\\Release\\Tests\\": "tests/Release/"`）、`phpunit.xml.dist`（`tests/Release/` を足す）、`psalm.xml.dist`（`tests/Release` を足す）

**Interfaces:**
- Produces: `final class JmaXml\Release\Tests\Support\Shell`
  - `public static function run(string $cwd, string $command): array{int, string}`：`sh -c` で実行し、終了コードと出力（標準出力と標準エラー）を返す
  - `public static function library(string $name): string`：`bin/lib/<name>.sh` の絶対パス
  - `public static function temporaryDirectory(): string` と `public static function remove(string $directory): void`
- Produces: `package_release_remote PACKAGE`（`bin/lib/package-release.sh`）：分割先の push 先を出力する。既定は `git@github.com:shimomo/jmaxml-php-<package>.git`。環境変数 `JMAXML_SPLIT_REMOTE_PREFIX` があれば、`git@github.com:shimomo/jmaxml-php-` の代わりにそれを前に付ける（テストでローカルの bare リポジトリを指すため）。`package_release_publish` もこれを使う

- [ ] **Step 1: 失敗するテストを書く**

```php
#[Test] public function updatesMutualRequiresAndBranchAlias(): void
// 一時ディレクトリに packages/core/composer.json（{"name": "jmaxml/core", "extra": {"branch-alias": {"dev-main": "0.1.x-dev"}}}）と
// packages/earthquake/composer.json（require: jmaxml/core ^0.1.0, psr/clock ^1.0）を置き、
// composer_version_update_package_requires 0.2.0 と composer_version_update_branch_alias 0.2.0 を実行
// → earthquake の require."jmaxml/core" === "^0.2.0"、require."psr/clock" === "^1.0"、両方の branch-alias === "0.2.x-dev"、インデントは 4 スペース

#[Test] public function mergeKeepsTheRootComposerJsonAsItIs(): void
// リポジトリのルートの composer.json と packages/*/composer.json を一時ディレクトリに同じ配置で写し、
// composer_merge_packages_into_root を実行 → require、require-dev、autoload、autoload-dev が元のルートと同じ（キーの順序は問わない）

#[Test] public function splitRemoteCanBePointedElsewhere(): void
// package_release_remote core === 'git@github.com:shimomo/jmaxml-php-core.git'
// JMAXML_SPLIT_REMOTE_PREFIX=/tmp/x/jmaxml-php- のとき package_release_remote feed === '/tmp/x/jmaxml-php-feed.git'
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Release`
Expected: FAIL（`bin/lib/*.sh` が無い）

- [ ] **Step 3: スクリプトを写して名前を直す**

- `boatrace/` を `jmaxml/` に、`shimomo/boatrace-php-` を `shimomo/jmaxml-php-` にする。コメントの「boatrace」も直す。monorepo-builder からの移行の経緯を書いたコメントは削ってよい
- `package_release_publish` の push 先は `package_release_remote` から取る
- 処理の流れとコマンドは変えない（`git push origin main`、`git tag -s`、`git subtree split`、`gh release create` を含む）

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit` と `vendor/bin/psalm --no-progress` と `vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add bin tests/Release composer.json phpunit.xml.dist psalm.xml.dist
git commit -m "chore: add release scripts ported from boatrace-php"
```

---

### Task 2: 手書きの「Unreleased」をそのまま使う

`changelog_stamp_release` は「## Unreleased」の直後に版の見出しとコミットの件名を差し込む。初回は全コミットが対象なので、手書きの紹介の上に件名が並んでしまう。

**Files:**
- Modify: `bin/lib/changelog.sh`、`bin/release.sh`（手順 1）
- Test: `tests/Release/ChangelogTest.php`

**Interfaces:**
- Consumes: `Shell`（Task 1）
- Produces: `changelog_unreleased_is_empty`：「## Unreleased」から次の「## 」見出し（またはファイルの終わり）までに空行しか無ければ終了コード 0、項目があれば 1
- Produces: `changelog_stamp_release VERSION COMMITS`：COMMITS が空のときは、版の見出しだけを差し込む（空行を重ねない）
- `bin/release.sh` の手順 1：`changelog_unreleased_is_empty` のときだけ `changelog_collect_commits` を使い、そうでなければ COMMITS を空にする

- [ ] **Step 1: 失敗するテストを書く**

日付は `\d{4}-\d{2}-\d{2}` で照合する。

```php
#[Test] public function handWrittenUnreleasedNotesBecomeTheRelease(): void
// "# Changelog\n\n## Unreleased\n\n- jmaxml/core を追加\n"
// changelog_unreleased_is_empty → 1、changelog_stamp_release 0.1.0 '' のあと
// → "# Changelog\n\n## Unreleased\n\n## [0.1.0] - <日付>\n\n- jmaxml/core を追加\n"

#[Test] public function commitSubjectsFillAnEmptyUnreleasedSection(): void
// "# Changelog\n\n## Unreleased\n\n## [0.1.0] - 2026-11-04\n\n- jmaxml/core を追加\n"
// changelog_unreleased_is_empty → 0、changelog_stamp_release 0.1.1 '- fix: a' のあと
// → "# Changelog\n\n## Unreleased\n\n## [0.1.1] - <日付>\n\n- fix: a\n\n## [0.1.0] - 2026-11-04\n\n- jmaxml/core を追加\n"
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Release/ChangelogTest.php`
Expected: FAIL（`changelog_unreleased_is_empty` が無い。1 つ目は空行が重なる）

- [ ] **Step 3: `changelog_unreleased_is_empty` を足し、`changelog_stamp_release` と `bin/release.sh` の手順 1 を直す**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Release` と `vendor/bin/psalm --no-progress`
Expected: OK、No errors found

- [ ] **Step 5: コミット**

```bash
git add bin tests/Release
git commit -m "chore: keep hand-written Unreleased notes when releasing"
```

---

### Task 3: push する前の事前確認

boatrace-php の仕組みには事前確認が無い。途中で失敗すると、main とタグは公開されたのに一部のパッケージだけ出ていない、という状態になる。初回で起こりやすいのは、分割先のリポジトリを作り忘れていることと、タグの打ち直し。

**Files:**
- Create: `bin/lib/preflight.sh`
- Modify: `bin/release.sh`（ほかの処理より前に `release_preflight "$version"` を呼ぶ。`preflight.sh` は `package-release.sh` の後に読み込む）
- Test: `tests/Release/PreflightTest.php`

**Interfaces:**
- Consumes: `Shell`、`package_release_remote`（Task 1）
- Produces: `release_preflight VERSION`：すべて通れば終了コード 0。1 つでも駄目なら、理由を標準エラーに出して終了コード 1。何も書き換えない。確かめること：
  1. VERSION が `^[0-9]+\.[0-9]+\.[0-9]+$`
  2. 今のブランチが `main`
  3. 作業ツリーがきれい（`git status --porcelain` が空）
  4. タグ VERSION が手元にも origin にも無い（origin は `git ls-remote --tags origin`）
  5. `git fetch origin main` の後、`HEAD` と `origin/main` が同じ
  6. `packages/*` のどれについても、`git ls-remote "$(package_release_remote <package>)"` が成功する

- [ ] **Step 1: 失敗するテストを書く**

準備：一時ディレクトリに、`packages/{core,feed}/composer.json` を持つリポジトリ（ブランチ `main`、コミット 1 つ）を作る。あわせて、それを push した bare の `origin.git` と、分割先の bare リポジトリ `jmaxml-php-core.git`、`jmaxml-php-feed.git` を作る。`JMAXML_SPLIT_REMOTE_PREFIX=<一時ディレクトリ>/jmaxml-php-` を付けて実行する。

```php
#[Test] public function passesOnACleanMainInStepWithOrigin(): void
// release_preflight 0.1.0 → 終了コード 0

#[Test] #[DataProvider('brokenStates')] public function stopsBeforeAnythingIsPushed(string $setup, string $version, string $reason): void
// 準備の後に $setup を実行し、release_preflight $version → 終了コード 1、出力に $reason を含む
// yield 'bad version'          => ['true', 'v0.1.0', 'version'];
// yield 'not on main'          => ['git checkout -q -b topic', '0.1.0', 'main'];
// yield 'dirty tree'           => ['echo x > untracked.txt', '0.1.0', 'clean'];
// yield 'tag on origin'        => ['git tag 0.1.0 && git push -q origin 0.1.0 && git tag -d 0.1.0', '0.1.0', '0.1.0'];
// yield 'behind origin'        => ['git commit -q --allow-empty -m x && git push -q origin main && git reset -q --hard HEAD~1', '0.1.0', 'origin/main'];
// yield 'missing split repo'   => ['rm -rf ../jmaxml-php-feed.git', '0.1.0', 'jmaxml-php-feed'];
```

（`$setup` は準備したリポジトリの中で実行する。分割先の bare リポジトリは、そのひとつ上のディレクトリに置く）

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Release/PreflightTest.php`
Expected: FAIL（`bin/lib/preflight.sh` が無い）

- [ ] **Step 3: `release_preflight` を実装し、`bin/release.sh` の最初で呼ぶ**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit` と `vendor/bin/psalm --no-progress` と `vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add bin tests/Release
git commit -m "chore: check the repository and split remotes before releasing"
```

---

### Task 4: リハーサルと README

**Files:**
- Modify: `README.md`（ルート）

- [ ] **Step 1: 分割のリハーサル（push しない）**

Run（パッケージごとに）：`git subtree split --prefix=packages/<package> --branch=rehearsal-<package>`、`git ls-tree --name-only rehearsal-<package>`、`git branch -D rehearsal-<package>`
Expected: 4 つとも、ツリーの直下が `.gitattributes`、`LICENSE`、`README.md`、`composer.json`、`src`、`tests` だけ

Run：`composer validate --strict packages/<package>/composer.json`（4 つとも）
Expected: `is valid`（警告なし）。警告が出たら、その `composer.json` を直す

- [ ] **Step 2: ルートの README に書く**

- パッケージの表に Packagist へのリンク（`https://packagist.org/packages/jmaxml/<package>`）を足す
- 「リリース」の節を足す：
  - `bin/release.sh <version>` で 4 パッケージを同じ版番号で公開すること
  - 実行の前提：分割先の 4 リポジトリがあること、`gh` にログイン済み、`jq`・`composer`、タグに署名できる GPG
  - CHANGELOG の決まり：手書きの「Unreleased」があればそれを使い、無ければ直前のタグ以降のコミットの件名を使う
  - 版番号の決まり：コード表の更新はパッチ版、公開 API が変わるときはマイナー版
  - 途中で失敗したときの再開の手順：事前確認の後に失敗したら、main とタグが公開されているかを確かめる。公開済みなら、残りのパッケージについて `package_release_publish <version> packages/<package>` を実行する

Run: `grep -n "bin/release.sh" README.md`
Expected: リリースの節が見つかる

- [ ] **Step 3: コミット**

```bash
git add README.md
git commit -m "docs: describe how jmaxml packages are released"
```

---

## マージ後の手順（この計画のタスクではない）

外に出る操作なので、ひとつずつ作者に確認してから進める。

1. 作者が、空の public リポジトリ `shimomo/jmaxml-php-{core,codes,feed,earthquake}` を作る（README なし）
2. main で `bin/release.sh 0.1.0` を実行する。事前確認が通ることを見てから進める
3. 作者が、4 つのリポジトリを Packagist に登録する（GitHub との連携で、以後のタグは自動で反映される）
4. 一時ディレクトリで `composer require jmaxml/earthquake:^0.1` を実行し、`jmaxml/core` と `jmaxml/codes` も `0.1.0` で入ることを確かめる
