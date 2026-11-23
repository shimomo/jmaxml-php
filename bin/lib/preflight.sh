#!/bin/sh
# bin/lib/preflight.sh
#
# リリースで何かを書き換えたり push したりする前に、失敗の原因をつぶしておく確認。
# 途中で失敗すると、タグは公開されたのに一部のパッケージだけ出ていない、という
# 状態になるので、起こりやすい原因（分割先のリポジトリの作り忘れ、タグの打ち直しなど）を先に見る。
# リリースは 2 段階（prepare：版を上げる PR を作る、publish：マージ後の main にタグを打って公開する）で、
# 段階ごとに確かめることが少し違う。
#
# package_release_remote を使うので、bin/lib/package-release.sh の後に読み込む。
# release.sh から `. bin/lib/preflight.sh` で読み込んで使う想定。

set -eu

# release_latest_tag
#
# x.y.z の形のタグのうち、いちばん新しい版を出力する（無ければ空）。
release_latest_tag() {
  git tag -l | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -n 1
}

# release_branch VERSION
#
# prepare が版を上げるコミットを置くブランチの名前（release/VERSION）を出力する。
release_branch() {
  echo "release/$1"
}

# release_preflight STEP VERSION
#
# STEP は prepare か publish。どちらにも共通の確認に、段階ごとの確認を足す。
#   prepare  CHANGELOG.md にまだ VERSION の節が無いこと（あれば prepare の PR はもうマージ済み）。
#            release/VERSION のブランチが手元にも origin にも無いこと
#   publish  prepare の PR がマージ済みであること。つまり CHANGELOG.md に VERSION の節があり、
#            packages/*/composer.json の jmaxml/* への依存がすべて ^VERSION であること
# すべて通れば終了コード 0。1 つでも駄目なら、見つかった理由をすべて標準エラーに出して 1 を返す。
# 作業ツリーもタグも書き換えない（origin/main を最新にする fetch だけを行う）。
release_preflight() {
  step="$1"
  version="$2"
  problems=''

  case "$step" in
    prepare | publish) ;;
    *)
      problems="${problems}- the step must be prepare or publish (got: $step)
"
      ;;
  esac

  if ! printf '%s\n' "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    problems="${problems}- the version must look like 0.1.0 (got: $version)
"
  fi

  branch=$(git symbolic-ref --quiet --short HEAD || echo '')

  if [ "$branch" != main ]; then
    problems="${problems}- releases are made from main (current branch: ${branch:-detached HEAD})
"
  fi

  if [ -n "$(git status --porcelain)" ]; then
    problems="${problems}- the working tree is not clean; commit or stash the changes first
"
  fi

  if git rev-parse --quiet --verify "refs/tags/$version" > /dev/null; then
    problems="${problems}- tag $version already exists locally
"
  fi

  # 打ち間違い（0.1.10 のつもりの 0.1.1 など）は公開すると取り消せないので、直前の版より新しいことを確かめる。
  latest=$(release_latest_tag)

  if [ -n "$latest" ] && { [ "$version" = "$latest" ] || [ "$(printf '%s\n%s\n' "$latest" "$version" | sort -V | tail -n 1)" != "$version" ]; }; then
    problems="${problems}- the version must be newer than $latest, the latest tag
"
  fi

  # --exit-code: 0 = the tag exists, 2 = it does not, anything else = origin could not be read.
  status=0
  git ls-remote --exit-code --tags origin "refs/tags/$version" > /dev/null 2>&1 || status=$?

  if [ "$status" -eq 0 ]; then
    problems="${problems}- tag $version already exists on origin
"
  elif [ "$status" -ne 2 ]; then
    problems="${problems}- cannot read the tags of origin
"
  fi

  if ! git fetch --quiet origin main 2> /dev/null; then
    problems="${problems}- cannot fetch main from origin
"
  elif [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
    problems="${problems}- HEAD is not the same commit as origin/main; pull or push first
"
  fi

  for directory in packages/*; do
    package=$(basename "$directory")
    remote=$(package_release_remote "$package")

    if ! git ls-remote "$remote" > /dev/null 2>&1; then
      problems="${problems}- the split repository for $package cannot be reached: $remote
"
    fi
  done

  # 版の節の見出しは changelog_stamp_release が書く "## [VERSION] - YYYY-MM-DD"。
  stamped=false

  if grep -Fq "## [$version] - " CHANGELOG.md 2> /dev/null; then
    stamped=true
  fi

  if [ "$step" = prepare ]; then
    if [ "$stamped" = true ]; then
      problems="${problems}- CHANGELOG.md already has a section for $version; its pull request is merged, so run bin/release.sh publish $version
"
    fi

    branch_name=$(release_branch "$version")

    if git rev-parse --quiet --verify "refs/heads/$branch_name" > /dev/null; then
      problems="${problems}- branch $branch_name already exists locally
"
    fi

    # --exit-code: 0 = the branch exists, 2 = it does not, anything else = origin could not be read.
    status=0
    git ls-remote --exit-code --heads origin "refs/heads/$branch_name" > /dev/null 2>&1 || status=$?

    if [ "$status" -eq 0 ]; then
      problems="${problems}- branch $branch_name already exists on origin; finish or close its pull request first
"
    elif [ "$status" -ne 2 ]; then
      problems="${problems}- cannot read the branches of origin
"
    fi
  fi

  if [ "$step" = publish ]; then
    if [ "$stamped" = false ]; then
      problems="${problems}- CHANGELOG.md has no section for $version; run bin/release.sh prepare $version and merge its pull request first
"
    fi

    # 読むだけなので、jq 1.7 で落ちる `(.x // {}) |= ...` の形は使わない。
    for file in packages/*/composer.json; do
      [ -f "$file" ] || continue

      stale=$(jq -r --arg constraint "^$version" '
        [
          [(.require // {}), (."require-dev" // {})] | add | to_entries[]
          | select((.key | startswith("jmaxml/")) and .value != $constraint)
          | .key + " " + .value
        ] | join(", ")
      ' "$file")

      if [ -n "$stale" ]; then
        problems="${problems}- $file does not require ^$version of the other packages ($stale); merge the prepare pull request first
"
      fi
    done
  fi

  if [ -n "$problems" ]; then
    printf 'Release stopped before changing anything:\n%s' "$problems" >&2
    return 1
  fi
}

# release_confirm VERSION
#
# 何を公開するか（版、直前の版、コミット、パッケージ）を出して、標準入力で y を受け取ったときだけ 0 を返す。
# それ以外（n、空、入力の終わり）は 1 を返す。n と答えれば、事前確認だけを試すことにもなる。
release_confirm() {
  version="$1"

  printf 'Release %s (previous: %s)\n' "$version" "$(release_latest_tag | grep . || echo none)"
  printf '  commit:   %s\n' "$(git log -1 --no-show-signature --format='%h %s')"
  printf '  packages:'

  for directory in packages/*; do
    printf ' %s' "$(basename "$directory")"
  done

  printf '\nThe tag and the split repositories cannot be taken back. Publish %s? [y/N] ' "$version"

  answer=''
  read -r answer || true

  case "$answer" in
    y | Y | yes | YES) return 0 ;;
  esac

  printf '\nRelease cancelled before changing anything.\n' >&2
  return 1
}
