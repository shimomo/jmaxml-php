#!/bin/sh
# bin/lib/composer-version.sh
#
# composer.json のバージョン関連フィールドを更新するヘルパー群。
# パッケージ間の依存の制約と branch-alias を更新し、
# bin/lib/composer-merge.sh でルートへ反映する。boatrace-php の同名スクリプトを写したもの。
#
# ルート composer.json の `replace` は `self.version` で書いているため、
# リリースごとに書き換える必要は無い。
#
# 依存: bin/lib/composer-merge.sh
#   （composer_version_sync_monorepo 内で composer_merge_packages_into_root
#    を呼ぶため、release.sh 側で本ファイルより先に
#    composer-merge.sh を source しておくこと）
#
# release.sh から `. bin/lib/composer-version.sh` で読み込んで使う想定。

set -eu

# composer_version_update_package_requires VERSION
#
# packages/*/composer.json の require / require-dev のうち、
# jmaxml/* への依存（パッケージ間の相互依存）を
# `^VERSION` に更新する。
#
# 例: packages/earthquake/composer.json の
#     require."jmaxml/core" を "^0.1.0" -> "^0.2.0" に更新する。
#
# 注意:
#   - jmaxml/* 以外の依存（phpunit, psalm など）には触れない
#   - require / require-dev のどちらに jmaxml/* が
#     書かれていても対応できるよう両方を更新対象にしている
#   - バージョン制約のフォーマットは `^x.y.z` 固定（複合制約は使わない）。
composer_version_update_package_requires() {
  version="$1"
  constraint="^$version"

  for dir in packages/*; do
    f="$dir/composer.json"
    [ -f "$f" ] || continue

    # `(.require // {}) |= ...` は jq 1.7 では、そのキーが無いと
    # "Invalid path expression" で失敗するので、あるときだけ更新する。
    jq --indent 4 --arg v "$constraint" '
      def bump: with_entries(
        if (.key | startswith("jmaxml/")) then .value = $v else . end
      );
      (if has("require") then .require |= bump else . end)
      | (if has("require-dev") then ."require-dev" |= bump else . end)
    ' "$f" > "$f.tmp"
    mv "$f.tmp" "$f"
  done
}

# composer_version_update_branch_alias VERSION
#
# 各 packages/*/composer.json の extra.branch-alias.dev-main を
# "<major>.<minor>.x-dev" 形式に更新する。
# 例: version=0.3.0 -> "0.3.x-dev"
#
# 通常のパッチ/マイナーリリースでは <major>.<minor> 部分は
# 変わらないことが多いが、minor/majorが上がるリリースのタイミングでは
# このタイミングで合わせて更新する必要がある。
composer_version_update_branch_alias() {
  version="$1"
  major_minor=$(echo "$version" | cut -d. -f1,2)
  alias="${major_minor}.x-dev"

  for dir in packages/*; do
    f="$dir/composer.json"
    [ -f "$f" ] || continue

    jq --indent 4 --arg v "$alias" \
      '.extra["branch-alias"]["dev-main"] = $v' \
      "$f" > "$f.tmp"
    mv "$f.tmp" "$f"
  done
}

# composer_version_sync_monorepo
#
# composer.json 群の更新後に、パッケージ側の定義をルートへ反映し、
# 依存関係・フォーマットを同期する。
#
#   1. composer_merge_packages_into_root
#      （bin/lib/composer-merge.sh）で packages/* の
#      require / require-dev / autoload / autoload-dev を
#      ルート composer.json にフォールドする
#   2. composer update でロックファイルを同期
#   3. composer normalize でフォーマットを整える
#
# インデントは 4 スペースに固定する。jq は既存のインデントを保たず
# 既定の 2 スペースで書き出し、composer normalize は既存のインデントを
# 検出して踏襲するので、指定が無いと 2 へ潰れる（boatrace-php の 0.1.0 で実際に潰れた）。
composer_version_sync_monorepo() {
  composer_merge_packages_into_root
  composer update
  composer normalize --indent-size=4 --indent-style=space
}

# composer_version_release VERSION
#
# 上記3関数をまとめて実行する、リリース時に呼び出す入口関数。
# release.sh からはこの1関数だけ呼べばよい。
composer_version_release() {
  version="$1"

  composer_version_update_package_requires "$version"
  composer_version_update_branch_alias "$version"
  composer_version_sync_monorepo
}
