#!/bin/sh
# bin/lib/package-release.sh
#
# packages/* 配下の1パッケージを、独立した GitHub リポジトリ
# （shimomo/jmaxml-php-<package>）へ subtree split で切り出し、
# push & GitHub Release 作成までを行うヘルパー。
# release.sh から `. bin/lib/package-release.sh` で読み込んで使う想定。

set -eu

# package_release_remote PACKAGE
#
# 分割先リポジトリの push 先（git@github.com:shimomo/jmaxml-php-<package>.git）を出力する。
# 環境変数 JMAXML_SPLIT_REMOTE_PREFIX があれば、
# "git@github.com:shimomo/jmaxml-php-" の代わりにそれを前に付ける（テストでローカルの bare リポジトリを指すため）。
package_release_remote() {
  echo "${JMAXML_SPLIT_REMOTE_PREFIX:-git@github.com:shimomo/jmaxml-php-}$1.git"
}

# package_release_publish VERSION DIRECTORY
#
# 1パッケージ分のリリース処理一式。
#   1. そのパッケージリポジトリの「現在のLatestリリースタグ」を控える
#      （Full Changelog の比較起点として後で使うため。
#       これを取得し損ねると、GitHub側の自動推測に頼ることになり、
#       過去に "Full Changelog: commits/x.y.z" という
#       単一コミットへの壊れたリンクになる不具合が再発する）
#   2. packages/<name> 配下だけの履歴を git subtree split で取り出す
#   3. 取り出したコミットを署名付きタグで固定する（一時タグ）
#   4. 独立リポジトリの main ブランチへ force push
#      （force push前提の運用のため、各パッケージリポジトリの
#        main ブランチ履歴は毎回ルートの最新状態で上書きされる）
#   5. 一時タグを正式なバージョンタグとして独立リポジトリへ push
#   6. ローカルの一時タグ/作業ブランチを掃除
#   7. GitHub Release を作成。直前のリリースタグが取れていれば
#      --notes-start-tag で明示し、Full Changelog リンクを正しく出す
#
# 引数:
#   VERSION   リリースするバージョン文字列（例: 0.1.0）
#   DIRECTORY パッケージのディレクトリパス（例: packages/earthquake）
package_release_publish() {
  version="$1"
  directory="$2"

  package=$(basename "$directory")
  # composer パッケージ名は jmaxml/<package> だが、GitHub 上は
  # shimomo 配下に jmaxml-php- プレフィックス付きで置く。
  repository="shimomo/jmaxml-php-$package"
  remote=$(package_release_remote "$package")

  # tmp_tag はあくまでローカル作業用の名前。リモートには
  # ステップ5で "refs/tags/${version}" という別名で push する。
  tmp_tag="pkg-${package}-${version}"

  echo "==> ${package}"

  # --- 0. 前回の失敗で残った一時タグと作業ブランチを消す --------------
  # 成功すればステップ6で消えるが、途中で失敗すると残り、
  # やり直したときに tag -s や subtree split が失敗する。どちらもローカルだけのもの。
  git tag -d "$tmp_tag" > /dev/null 2>&1 || true
  git branch -D "split-$package" > /dev/null 2>&1 || true

  # --- 1. 直前のLatestリリースタグを記録 -----------------------------
  # 新タグをpushする前に取得すること。pushした後に取ると
  # 今回作成するバージョン自身を拾ってしまう。
  # まだ一度もリリースしていないパッケージでは空文字になる
  # （gh release view が失敗するので || echo '' で握りつぶす）。
  prev_version=$(gh release view \
    --repo "$repository" \
    --json tagName \
    --jq .tagName 2>/dev/null || echo '')

  # --- 2. packages/<name> 配下だけの履歴を切り出す --------------------
  commit=$(git subtree split \
    --prefix="$directory" \
    --branch="split-$package")

  # --- 3. 切り出したコミットに署名付き一時タグを打つ -------------------
  git tag -s "$tmp_tag" "$commit" -m "Release $version"

  # --- 4. 独立リポジトリの main を force push ------------------------
  # subtree split は毎回ルート側の現在の履歴から再構成するため、
  # 過去のpush内容と完全一致しないことがある。
  # よって force push が前提の運用になっている。
  # push 元が生の SHA なので、宛先は refs/heads/ まで書く。"main" だけだと
  # git はリモートの既存 main から補完しようとし、空のリポジトリでは失敗する。
  git push "$remote" "$commit:refs/heads/main" --force

  # --- 5. バージョンタグを独立リポジトリへ push -----------------------
  git push "$remote" "refs/tags/${tmp_tag}:refs/tags/${version}"

  # --- 6. ローカルの一時タグ・作業ブランチを掃除 -----------------------
  git tag -d "$tmp_tag"
  git branch -D "split-$package"

  # --- 7. GitHub Release を作成 ---------------------------------------
  # prev_version が取れていれば --notes-start-tag で比較起点を明示する。
  # これをやらないと GitHub 側の自動推測が失敗し、
  # "Full Changelog: .../commits/<version>" という
  # 単一コミットへのリンクになってしまうケースがある
  # (GitHub は祖先関係にあるタグでも、Releaseとして公開されていないと
  #  自動では前回リリースとして認識できないことがある)。
  if [ -n "$prev_version" ]; then
    gh release create "$version" \
      --repo "$repository" \
      --notes-start-tag "$prev_version" \
      --generate-notes \
      || true
  else
    # 初回リリースなど、比較対象となる前回タグが存在しない場合
    gh release create "$version" \
      --repo "$repository" \
      --generate-notes \
      || true
  fi
}
