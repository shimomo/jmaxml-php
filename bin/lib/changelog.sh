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

# changelog_unreleased_is_empty
#
# "## Unreleased" から次の "## " 見出し（またはファイルの終わり）までに
# 空行しか無ければ終了コード 0、手書きの項目があれば 1 を返す。
# 見出しが無いときも 0 を返す。
changelog_unreleased_is_empty() {
  awk '
    /^## / { inside = ($0 == "## Unreleased"); next }
    inside && NF > 0 { found = 1 }
    END { exit found ? 1 : 0 }
  ' CHANGELOG.md
}

# changelog_stamp_release VERSION COMMITS
#
# CHANGELOG.md の "## Unreleased" 見出しの直後に
# "## [VERSION] - YYYY-MM-DD" ブロックを挿入し、
# その下に COMMITS（changelog_collect_commits の出力）を書き込む。
# COMMITS が空なら見出しだけを入れるので、"Unreleased" に手書きした項目が
# そのまま今回の版の項目になる。見出しの後の空行は 1 行にそろえる。
#
# 例: Unreleased の直後がこうなる
#   ## Unreleased
#
#   ## [0.1.0] - 2026-09-10
#
#   - feat: foo
#   - fix: bar
#
# 日付は date で取る（strftime は gawk にしか無い）。
#
# 注意: "## Unreleased" 見出しが存在しない場合は何も変化しない
#       （= リリースバージョンが追記されない）ので、
#       事前に見出しの存在を保証しておくこと。
changelog_stamp_release() {
  version="$1"
  commits="$2"
  today=$(date +%Y-%m-%d)

  awk -v commits="$commits" -v version="$version" -v today="$today" '
    /^## Unreleased$/ {
      print
      print ""
      print "## [" version "] - " today

      if (commits != "") {
        print ""
        print commits
      }

      skipping = 1
      next
    }
    skipping && NF == 0 { next }
    skipping { print ""; skipping = 0 }
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
