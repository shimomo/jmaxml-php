#!/bin/sh
# bin/merge.sh
#
# packages/*/composer.json の定義をルート composer.json へ反映し、
# composer update と composer normalize まで行うスクリプト。
# リリースを伴わずに composer.json 群だけを同期したいときに使う。
#
# 使い方:
#   bin/merge.sh
#
# 依存:
#   - bin/lib/composer-merge.sh    packages/* の require/autoload等を
#                                  ルート composer.json へマージする関数
#   - bin/lib/composer-version.sh  composer_version_sync_monorepo
#   - jq, composer（normalize, update）がインストールされていること

set -eu

script_dir=$(cd "$(dirname "$0")" && pwd)

# shellcheck source=lib/composer-merge.sh
. "$script_dir/lib/composer-merge.sh"
# shellcheck source=lib/composer-version.sh
. "$script_dir/lib/composer-version.sh"

composer_version_sync_monorepo
