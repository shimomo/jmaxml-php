#!/bin/sh
# bin/lib/changelog.sh
#
# CHANGELOG.md の読み書きに関するヘルパー関数群。
# release.sh から `. bin/lib/changelog.sh` で読み込んで使う想定。
# 単体では実行しない（関数定義のみ）。

set -eu

# changelog_collect_commits LAST_TAG
#
# 直前のタグ（LAST_TAG）から HEAD までのコミットを
# "- subject" 形式の箇条書きで標準出力に返す。
# LAST_TAG が空文字（= タグがまだ一つも無い初回リリース）の場合は
# リポジトリ全コミットを対象にする。
changelog_collect_commits() {
  last_tag="$1"

  # log.showSignature が有効だと --pretty の出力に GPG の検証メッセージが
  # 混ざり、そのまま CHANGELOG.md に書き込まれる（初回リリースで実際に混入した）。
  # 件名だけを取りたいので明示的に抑止する。

  if [ -n "$last_tag" ]; then
    git log --no-show-signature "${last_tag}..HEAD" --pretty='- %s'
  else
    git log --no-show-signature --pretty='- %s'
  fi
}

# changelog_stamp_release VERSION COMMITS
#
# CHANGELOG.md の "## Unreleased" 見出しの直後に
# "## [VERSION] - YYYY-MM-DD" ブロックを挿入し、
# その下に COMMITS（changelog_collect_commits の出力）を書き込む。
#
# 例: Unreleased の直後がこうなる
#   ## Unreleased
#
#   ## [0.1.0] - 2026-09-10
#
#   - feat: foo
#   - fix: bar
#
# 注意: "## Unreleased" 見出しが存在しない場合は何も変化しない
#       （= リリースバージョンが追記されない）ので、
#       事前に見出しの存在を保証しておくこと。
changelog_stamp_release() {
  version="$1"
  commits="$2"

  awk -v commits="$commits" -v version="$version" '
    /^## Unreleased$/ {
      print
      print ""
      print "## [" version "] - " strftime("%Y-%m-%d")
      print ""
      print commits
      print ""
      next
    }
    { print }
  ' CHANGELOG.md > CHANGELOG_NEW.md

  mv CHANGELOG_NEW.md CHANGELOG.md
}

# changelog_ensure_unreleased_heading
#
# CHANGELOG.md に "## Unreleased" 見出しが無ければ、
# "# Changelog" の直後に挿入して復元する。
# changelog_stamp_release で消費された見出しを次回リリースに備えて
# 再生成するために、リリース完了後に呼び出す想定。
changelog_ensure_unreleased_heading() {
  if grep -q '^## Unreleased$' CHANGELOG.md; then
    return 0
  fi

  awk '
    /^# Changelog$/ {
      print
      print ""
      print "## Unreleased"
      next
    } { print }
  ' CHANGELOG.md > CHANGELOG_NEW.md

  mv CHANGELOG_NEW.md CHANGELOG.md
}
