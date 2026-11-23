#!/bin/sh
# bin/lib/user-agent.sh
#
# jmaxml/feed の既定の User-Agent（FeedClient::DEFAULT_USER_AGENT）に版番号を書き込むヘルパー。
# 設計書 5.2 で、既定値にはライブラリ名とバージョンを入れることにしている。
# 手で直すと忘れるので（0.1.1 まで "jmaxml-feed/0.1" のままだった）、prepare で毎回書き込む。
# release.sh から `. bin/lib/user-agent.sh` で読み込んで使う想定。

set -eu

# user_agent_update VERSION
#
# packages/feed/src/FeedClient.php の
#   public const string DEFAULT_USER_AGENT = 'jmaxml-feed/<何か>';
# を 'jmaxml-feed/VERSION' に書き換える。その行がちょうど 1 つでなければ、何も変えずに 1 を返す
# （定数の書き方が変わったのに、黙って古い版のまま公開しないため）。
user_agent_update() {
  version="$1"
  file=packages/feed/src/FeedClient.php
  pattern="public const string DEFAULT_USER_AGENT = 'jmaxml-feed/[^']*';"

  count=$(grep -c "$pattern" "$file" 2> /dev/null || true)

  if [ "$count" != 1 ]; then
    echo "Cannot find exactly one DEFAULT_USER_AGENT line in $file (found: ${count:-0}); update the version there by hand" >&2
    return 1
  fi

  sed "s#\(public const string DEFAULT_USER_AGENT = 'jmaxml-feed/\)[^']*';#\1$version';#" "$file" > "$file.tmp"
  mv "$file.tmp" "$file"
}
