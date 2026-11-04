#!/bin/sh
# bin/release.sh
#
# モノレポ全体のリリースを実行するスクリプト。
#
# 使い方:
#   bin/release.sh <version>
#   例: bin/release.sh 0.1.0
#
# 全体の流れ:
#   0. 事前確認と、公開してよいかの確認（y と答えたときだけ進む）。駄目なら何も変えずに止まる
#   1. CHANGELOG.md の "Unreleased" セクションに今回のバージョンを
#      書き込み、対象コミット一覧を反映する
#   2. packages/*/composer.json と ルート composer.json の
#      バージョン関連フィールドを更新する
#   3. 上記の変更を "chore: prepare release" としてコミット・push する
#   4. ルートリポジトリにバージョンタグを打って push する
#   5. packages/* を1つずつ独立リポジトリへ subtree split し、
#      push & GitHub Release 作成を行う
#   6. CHANGELOG.md に次回リリース用の "Unreleased" 見出しを復元し、
#      別コミットとして push する
#
# 依存:
#   - bin/lib/preflight.sh         リリース前の確認（release_preflight）
#   - bin/lib/changelog.sh         CHANGELOG.md の読み書き関数
#   - bin/lib/composer-merge.sh    packages/* の require/autoload等を
#                                  ルート composer.json へマージする関数
#   - bin/lib/composer-version.sh  composer.json のバージョン更新関数
#   - bin/lib/package-release.sh   パッケージ単位のリリース処理関数
#   - GitHub CLI (gh) でログイン済みであること
#   - 各 packages/<name> に対応する shimomo/jmaxml-php-<name> リポジトリが
#     作成済みで、git push 権限（SSH）があること
#   - jq, composer（normalize, update）がインストールされていること

set -eu

version="${1:?Usage: bin/release.sh <version>}"

script_dir=$(cd "$(dirname "$0")" && pwd)

# shellcheck source=lib/changelog.sh
. "$script_dir/lib/changelog.sh"
# shellcheck source=lib/composer-merge.sh
. "$script_dir/lib/composer-merge.sh"
# shellcheck source=lib/composer-version.sh
. "$script_dir/lib/composer-version.sh"
# shellcheck source=lib/package-release.sh
. "$script_dir/lib/package-release.sh"
# shellcheck source=lib/preflight.sh
. "$script_dir/lib/preflight.sh"

# ---------------------------------------------------------------------------
# 0. 事前確認
# ---------------------------------------------------------------------------
# 何かを書き換えたり push したりする前に、版番号、ブランチ、作業ツリー、
# タグ、origin との差、分割先のリポジトリを確かめる。駄目なら理由を出して止まる。
# 通ったら、何を公開するかを出して y の入力を待つ（タグと分割先は取り消せないため）。
release_preflight "$version"
release_confirm "$version"

# ---------------------------------------------------------------------------
# 1. CHANGELOG.md に今回バージョンのセクションを書き込む
# ---------------------------------------------------------------------------
# "Unreleased" に手書きの項目があれば、それをそのまま今回の版の項目にする。
# 無ければ直前のタグ以降のコミットの件名を使う。
# タグが一つも無い場合（プロジェクト初回リリース）は全コミットを対象にする。
commits=''

if changelog_unreleased_is_empty; then
  last_tag=$(git describe --tags --abbrev=0 2>/dev/null || echo '')
  commits=$(changelog_collect_commits "$last_tag")
fi

changelog_stamp_release "$version" "$commits"

# ---------------------------------------------------------------------------
# 2. composer.json 群のバージョン情報を更新
# ---------------------------------------------------------------------------
#   - 各 packages/*/composer.json の `require` / `require-dev` のうち
#     jmaxml/* への依存を `^<version>` に更新
#   - 各 packages/*/composer.json の branch-alias を `<major>.<minor>.x-dev` に更新
#   - composer_version_sync_monorepo でルートへのマージ・依存関係・フォーマットを同期
#   - ルート composer.json の `replace` は `self.version` なので触らない
composer_version_release "$version"

# ---------------------------------------------------------------------------
# 3. 変更をコミットしてpush
# ---------------------------------------------------------------------------
# ここでコミットしておかないと、後続の subtree split に
# バージョン更新が含まれないまま各パッケージへ配布されてしまう。
git add composer.json packages/*/composer.json

if ! git diff --cached --quiet; then
  git commit -m "chore: prepare release $version"
fi

git push origin main

# ---------------------------------------------------------------------------
# 4. ルートリポジトリにバージョンタグを打ってpush
# ---------------------------------------------------------------------------
git tag -s "$version" -m "release $version"
git push origin "$version"

# ---------------------------------------------------------------------------
# 5. 各パッケージを独立リポジトリへ配信
# ---------------------------------------------------------------------------
# subtree split → force push → タグpush → GitHub Release作成 を
# パッケージごとに実行する。詳細は package_release_publish 内のコメント参照。
for directory in packages/*; do
  package_release_publish "$version" "$directory"
done

# ---------------------------------------------------------------------------
# 6. 次回リリースに備えて Unreleased 見出しを復元しコミット
# ---------------------------------------------------------------------------
# ステップ1の changelog_stamp_release で "## Unreleased" は
# バージョン見出しに置き換わって消費されているので、
# 次回リリースのために再度差し込んでおく。
# こちらは prepare release コミットとは別コミットとして残す
# （リリース本体の変更内容と、次回への準備作業を分離するため）。
changelog_ensure_unreleased_heading

git add CHANGELOG.md

if ! git diff --cached --quiet; then
  git commit -m 'chore: update CHANGELOG.md'
  git push origin main
fi

echo "==> Release $version completed"
