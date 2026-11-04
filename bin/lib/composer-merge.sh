#!/bin/sh
# bin/lib/composer-merge.sh
#
# packages/*/composer.json の require / require-dev /
# autoload.psr-4 / autoload-dev.psr-4 をルート composer.json に
# フォールド（統合）する。boatrace-php の同名スクリプトを写したもの。
#
#   - require, require-dev, autoload, autoload-dev の4セクションを
#     全パッケージから集めてルートにマージする
#   - 同じパッケージ名が require と require-dev の両方に出てきた場合は
#     require 側を優先する
#   - 既存セクションのキー順は維持し、新規キーは末尾に追加する
#   - ルートにだけあるキー（開発ツールなど）はそのまま残す
#
# 考慮事項:
#   - jmaxml/* 同士の相互依存（root composer.json の `replace` に
#     列挙されているパッケージ名）は、ルートの require には
#     積まない。replace により内部的に解決される依存であり、
#     ルート require に積むと「外部に存在しないバージョン制約付き
#     パッケージ」を要求することになり矛盾するため。
#   - 各パッケージの composer.json の autoload/autoload-dev は
#     パッケージディレクトリ起点の相対パス（例: "src/"）で
#     書かれているため、ルートにマージする際は
#     "packages/<package>/" を前置してパスを補正する。
#   - repositories セクションはルート側で固定運用しており
#     merge対象に含める必要が無いため、このスクリプトでは触れない。
#
# release.sh から `. bin/lib/composer-merge.sh` で読み込んで使う想定。

set -eu

# composer_merge_packages_into_root
#
# packages/*/composer.json を走査し、require / require-dev /
# autoload.psr-4 / autoload-dev.psr-4 を集約してルート composer.json に
# 書き戻す。
composer_merge_packages_into_root() {
  # 各パッケージのcomposer.jsonに、後でautoloadパスを補正するための
  # "_pkg_dir" を仕込んでから配列にまとめてjqへ渡す。
  package_jsons=$(
    for dir in packages/*; do
      f="$dir/composer.json"
      [ -f "$f" ] || continue
      jq --arg pkg_dir "$dir" '. + {_pkg_dir: $pkg_dir}' "$f"
    done | jq -s '.'
  )

  jq --indent 4 --argjson packages "$package_jsons" '
    # 複数オブジェクトを「後勝ち」でマージするヘルパー
    def merge_all: reduce .[] as $o ({}; . * $o);

    # ルート composer.json の replace に列挙されている名前 = 自社内部パッケージ。
    # これらへの require/require-dev はルートへ積まない（replaceで解決されるため）。
    (.replace // {} | keys) as $internal_names
    |
    # require / require-dev: 内部パッケージへの依存を除外してからマージ
    ($packages
      | map(.require // {} | with_entries(select(.key as $k | ($internal_names | index($k)) | not)))
      | merge_all) as $merged_require
    |
    ($packages
      | map(."require-dev" // {} | with_entries(select(.key as $k | ($internal_names | index($k)) | not)))
      | merge_all) as $merged_require_dev
    |
    # autoload / autoload-dev: パッケージディレクトリ相対のパスを
    # "packages/<dir>/" 起点のパスへ補正してからマージ
    ($packages
      | map(
          ._pkg_dir as $dir
          | (.autoload.["psr-4"] // {})
          | with_entries(.value = ($dir + "/" + .value))
        )
      | merge_all) as $merged_autoload
    |
    ($packages
      | map(
          ._pkg_dir as $dir
          | (."autoload-dev".["psr-4"] // {})
          | with_entries(.value = ($dir + "/" + .value))
        )
      | merge_all) as $merged_autoload_dev
    |
    # require と require-dev の両方にあるキーは require を優先する
    ($merged_require_dev | with_entries(select(.key as $k | ($merged_require | has($k)) | not)))
      as $merged_require_dev_deduped
    |
    .require = ((.require // {}) + $merged_require)
    | ."require-dev" = ((."require-dev" // {}) + $merged_require_dev_deduped)
    | .autoload.["psr-4"] = ((.autoload.["psr-4"] // {}) + $merged_autoload)
    | ."autoload-dev".["psr-4"] = ((."autoload-dev".["psr-4"] // {}) + $merged_autoload_dev)
  ' composer.json > composer.json.tmp
  mv composer.json.tmp composer.json
}
