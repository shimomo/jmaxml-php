<?php

declare(strict_types=1);

namespace JmaXml\Release\Tests\Support;

use RuntimeException;

/**
 * A throwaway release setup: repo/ (branch main with packages/core, packages/feed with a FeedClient.php that holds the
 * default User-Agent, a root composer.json that replaces both, and a CHANGELOG.md whose Unreleased section has one
 * entry, pushed to origin.git), the bare split repositories jmaxml-php-core.git and jmaxml-php-feed.git next to it, an
 * SSH key that signs tags in repo/, and in fake-bin/ a fake gh and a fake composer that log their calls to gh.log and
 * composer.log.
 *
 * @author shimomo
 */
final class Sandbox
{
    /**
     * Run in repo/: leaves main as merging the prepare pull request for 0.1.0 would.
     */
    public const string PREPARED = "printf '# Changelog\\n\\n## Unreleased\\n\\n## [0.1.0] - 2026-11-23\\n\\n- first\\n' > CHANGELOG.md"
        . " && git commit -q -am 'chore: prepare release 0.1.0' && git push -q origin main";

    private const string SETUP = <<<'SH'
        set -e
        sandbox=$(pwd)
        git init -q --bare origin.git
        git init -q --bare jmaxml-php-core.git
        git init -q --bare jmaxml-php-feed.git
        ssh-keygen -q -t ed25519 -N '' -C test -f signing-key
        mkdir fake-bin
        printf '%s\n' '#!/bin/sh' 'echo "gh $*" >> "$(dirname "$0")/../gh.log"' '[ "$1 $2" != "release view" ]' > fake-bin/gh
        printf '%s\n' '#!/bin/sh' 'echo "composer $*" >> "$(dirname "$0")/../composer.log"' > fake-bin/composer
        chmod +x fake-bin/gh fake-bin/composer
        git init -q -b main repo
        cd repo
        git config user.name test
        git config user.email test@example.com
        git config commit.gpgsign false
        git config tag.gpgSign false
        git config gpg.format ssh
        git config user.signingkey "$sandbox/signing-key"
        mkdir -p packages/core packages/feed
        echo '{}' > packages/core/composer.json
        echo '{}' > packages/feed/composer.json
        mkdir packages/feed/src
        printf '%s\n' '<?php' '' 'final class FeedClient' '{' "    public const string DEFAULT_USER_AGENT = 'jmaxml-feed/0.0.1';" '}' > packages/feed/src/FeedClient.php
        printf '# Changelog\n\n## Unreleased\n\n- first\n' > CHANGELOG.md
        printf '%s\n' '{"name": "test/test", "replace": {"jmaxml/core": "self.version", "jmaxml/feed": "self.version"}}' > composer.json
        git add .
        git commit -q -m init
        git remote add origin "$sandbox/origin.git"
        git push -q origin main
        SH;

    public static function create(): string
    {
        $sandbox = Shell::temporaryDirectory();
        [$exitCode, $output] = Shell::run($sandbox, self::SETUP);

        if ($exitCode !== 0) {
            Shell::remove($sandbox);

            throw new RuntimeException('Cannot build the release sandbox: ' . $output);
        }

        return $sandbox;
    }

    /**
     * Exports what the release libraries need to use the sandbox instead of GitHub, then runs $command in repo/.
     *
     * @return array{int, string}
     */
    public static function run(string $sandbox, string $command): array
    {
        return Shell::run($sandbox . '/repo', 'export JMAXML_SPLIT_REMOTE_PREFIX=' . escapeshellarg($sandbox . '/jmaxml-php-')
            . '; export PATH=' . escapeshellarg($sandbox . '/fake-bin') . ':"$PATH"; ' . $command);
    }
}
