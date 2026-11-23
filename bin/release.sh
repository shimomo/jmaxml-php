#!/bin/sh
# bin/release.sh
#
# モノレポ全体のリリースを 2 段階で行うスクリプト。どちらの段階も main には push しない
# （main はルールセットで PR 必須にしてあり、直接の push は通らない）。
#
# 使い方:
#   bin/release.sh prepare <version>   版を上げる PR を作る
#   bin/release.sh publish <version>   その PR をマージした後の main にタグを打ち、各パッケージを公開する
#   例: bin/release.sh prepare 0.1.2 → PR を squash マージ → git pull → bin/release.sh publish 0.1.2
#
# prepare の PR をマージするまで、ほかの PR をマージしない。その変更はタグに入るのに、
# 今回の版の CHANGELOG には載らない（マージした後なら、次の版に回るのでかまわない）。
#
# prepare の流れ:
#   1. 事前確認（release_preflight prepare）。駄目なら何も変えずに止まる
#   2. release/<version> ブランチを作って移る
#   3. CHANGELOG.md の "Unreleased" の下に今回の版の節を書く
#   4. packages/*/composer.json と ルート composer.json のバージョン関連フィールドと、
#      jmaxml/feed の既定の User-Agent（jmaxml-feed/<version>）を更新する
#   5. 3 と 4 を "chore: prepare release <version>" としてコミットし、ブランチを push して PR を作る
#   6. main に戻る
#
# publish の流れ:
#   1. 事前確認（release_preflight publish）と、公開してよいかの確認（y と答えたときだけ進む）
#   2. 版の節を足したコミット（prepare の PR をマージしたコミット）に署名付きのバージョンタグを打って push する
#   3. packages/* を1つずつ、タグの時点で独立リポジトリへ subtree split し、push & GitHub Release 作成を行う
#
# CHANGELOG の版の節は、版の書き換えと同じコミットに入れる。こうしておくと、
# タグを打つコミットの CHANGELOG に、その版の節が載る。
#
# 依存:
#   - bin/lib/preflight.sh         リリース前の確認（release_preflight、release_confirm）
#   - bin/lib/changelog.sh         CHANGELOG.md の読み書き関数
#   - bin/lib/composer-merge.sh    packages/* の require/autoload等を
#                                  ルート composer.json へマージする関数
#   - bin/lib/composer-version.sh  composer.json のバージョン更新関数
#   - bin/lib/package-release.sh   パッケージ単位のリリース処理関数
#   - bin/lib/user-agent.sh        jmaxml/feed の既定の User-Agent に版番号を書く関数
#   - GitHub CLI (gh) でログイン済みであること
#   - 各 packages/<name> に対応する shimomo/jmaxml-php-<name> リポジトリが
#     作成済みで、git push 権限（SSH）があること
#   - jq, composer（normalize, update）がインストールされていること
#
# 途中で失敗したとき（どこまで進んだかを確かめてから再開する）:
#   - prepare をやり直す（PR を作る前に止まった、マージ前の PR を作り直したい）
#     main には何も入っていない。PR があれば閉じてから、次を実行してやり直す。
#       git switch -f main                           # 書き換えた CHANGELOG.md と composer.json 群は捨てる
#       git branch -D release/<version>
#       git push origin --delete release/<version>   # ブランチを push した後なら
#   - publish でタグがまだ origin に無い（git ls-remote --tags origin <version> で見えない）
#     何も公開されていない。手元にだけタグがあれば git tag -d <version> で消してからやり直す。
#   - publish でタグが origin にある
#     公開は始まっている。まだ公開されていないパッケージについてだけ、次を実行する。
#       sh -c '. bin/lib/package-release.sh; package_release_publish <version> packages/<name>'
#     手元にタグが無ければ、先に git fetch --tags で取ってくる。分割はタグの時点で行うので、
#     main がその後に進んでいてもかまわない（前回の失敗で残った一時タグと作業ブランチは自動で消える）。
#   - GitHub Release だけ作れなかった（publish が最後に一覧を出して、終了コード 1 で終わる）
#     パッケージの公開は済んでいる。表示された gh release create のコマンドを実行する。
#   - 分割先の main への push が拒否された（GH013: Repository rule violations）
#     分割先の main はルールセットで force push を禁止している。新しい分割が前回の main の
#     続きになっていない（git のバージョンや分割の仕方を変えたときに起こりうる）。本体のタグは
#     もう公開されている。そのリポジトリの Settings → Rules → Rulesets で「main」を一時的に
#     Disabled にし、上の package_release_publish でそのパッケージだけ公開し直してから Active に戻す。

set -eu

usage() {
  echo 'usage: bin/release.sh prepare <version> | bin/release.sh publish <version>' >&2
  exit 1
}

[ $# -eq 2 ] || usage

step="$1"
version="$2"

case "$step" in
  prepare | publish) ;;
  *) usage ;;
esac

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
# shellcheck source=lib/user-agent.sh
. "$script_dir/lib/user-agent.sh"

release_prepare() {
  # -------------------------------------------------------------------------
  # 1. 事前確認
  # -------------------------------------------------------------------------
  # 何かを書き換えたり push したりする前に、版番号、ブランチ、作業ツリー、タグ、
  # origin との差、分割先のリポジトリ、CHANGELOG とリリース用のブランチを確かめる。
  release_preflight prepare "$version"

  # -------------------------------------------------------------------------
  # 2. リリース用のブランチを作る
  # -------------------------------------------------------------------------
  release_branch_name=$(release_branch "$version")
  git switch --quiet -c "$release_branch_name"

  # -------------------------------------------------------------------------
  # 3. CHANGELOG.md に今回バージョンのセクションを書き込む
  # -------------------------------------------------------------------------
  # "Unreleased" に手書きの項目があれば、それをそのまま今回の版の項目にする。
  # 無ければ直前のタグ以降のコミットの件名を使う。
  # タグが一つも無い場合（プロジェクト初回リリース）は全コミットを対象にする。
  # 見出しが無いと changelog_stamp_release は何も書かないので、先に見出しを保証する。
  changelog_ensure_unreleased_heading

  commits=''

  if changelog_unreleased_is_empty; then
    last_tag=$(git describe --tags --abbrev=0 2>/dev/null || echo '')
    commits=$(changelog_collect_commits "$last_tag")
  fi

  changelog_stamp_release "$version" "$commits"

  # -------------------------------------------------------------------------
  # 4. composer.json 群のバージョン情報を更新
  # -------------------------------------------------------------------------
  #   - 各 packages/*/composer.json の `require` / `require-dev` のうち
  #     jmaxml/* への依存を `^<version>` に更新
  #   - 各 packages/*/composer.json の branch-alias を `<major>.<minor>.x-dev` に更新
  #   - composer_version_sync_monorepo でルートへのマージ・依存関係・フォーマットを同期
  #   - ルート composer.json の `replace` は `self.version` なので触らない
  #   - jmaxml/feed の既定の User-Agent を `jmaxml-feed/<version>` にする
  composer_version_release "$version"
  user_agent_update "$version"

  # -------------------------------------------------------------------------
  # 5. コミットしてブランチを push し、PR を作る
  # -------------------------------------------------------------------------
  # CHANGELOG.md も同じコミットに入れる。マージ後の main にタグを打つので、
  # タグの時点の CHANGELOG に今回の版の節が載る。
  git add CHANGELOG.md composer.json packages/*/composer.json packages/feed/src/FeedClient.php
  git commit -m "chore: prepare release $version"
  git push -u origin "$release_branch_name"

  gh pr create \
    --base main \
    --head "$release_branch_name" \
    --title "chore: prepare release $version" \
    --body "$(release_pull_request_body)"

  # -------------------------------------------------------------------------
  # 6. main に戻る
  # -------------------------------------------------------------------------
  git switch --quiet main

  echo "==> Opened the pull request for $version. After it is merged, run git pull and then bin/release.sh publish $version"
}

# release_pull_request_body
#
# prepare が作る PR の本文。今回の版の CHANGELOG と、マージした後にすることを書く。
release_pull_request_body() {
  printf '## 概要\n\n%s を公開するために、CHANGELOG と composer.json 群の版を上げます。\n\n' "$version"
  printf '## CHANGELOG\n\n%s\n\n' "$(changelog_release_notes "$version")"
  printf '## マージした後\n\n```bash\ngit pull\nbin/release.sh publish %s\n```\n' "$version"
}

release_publish() {
  # -------------------------------------------------------------------------
  # 1. 事前確認
  # -------------------------------------------------------------------------
  # prepare の PR がマージ済みであること（CHANGELOG の節と相互依存の版）も確かめる。
  # 通ったら、何を公開するかを出して y の入力を待つ（タグと分割先は取り消せないため）。
  release_preflight publish "$version"
  release_confirm "$version"

  # -------------------------------------------------------------------------
  # 2. ルートリポジトリにバージョンタグを打って push
  # -------------------------------------------------------------------------
  # タグは main の先頭ではなく、版の節を足したコミット（release_commit）に打つ。
  # その後に main へ入ったコミットは、次の版の CHANGELOG に載る。
  git tag -s "$version" -m "release $version" "$(release_commit "$version")"
  git push origin "refs/tags/$version"

  # -------------------------------------------------------------------------
  # 3. 各パッケージを独立リポジトリへ配信
  # -------------------------------------------------------------------------
  # subtree split → force push → タグpush → GitHub Release作成 を
  # パッケージごとに実行する。詳細は package_release_publish 内のコメント参照。
  # GitHub Release を作れなかった分割先は package_release_failed に溜まる。最後にまとめて知らせる。
  package_release_failed=''

  for directory in packages/*; do
    package_release_publish "$version" "$directory"
  done

  if [ -n "$package_release_failed" ]; then
    echo "==> $version is published, but the GitHub Releases of these repositories are missing: $package_release_failed (create them with the commands above)" >&2
    exit 1
  fi

  echo "==> Release $version completed"
}

"release_$step"
