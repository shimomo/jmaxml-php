<?php

declare(strict_types=1);

namespace JmaXml\Release\Tests\Support;

use RuntimeException;

/**
 * A throwaway release setup: repo/ (branch main with packages/core and packages/feed, pushed to origin.git), the bare
 * split repositories jmaxml-php-core.git and jmaxml-php-feed.git next to it, an SSH key that signs tags in repo/, and
 * a fake gh in fake-bin/ that logs its calls to gh.log.
 *
 * @author shimomo
 */
final class Sandbox
{
    private const string SETUP = <<<'SH'
        set -e
        sandbox=$(pwd)
        git init -q --bare origin.git
        git init -q --bare jmaxml-php-core.git
        git init -q --bare jmaxml-php-feed.git
        ssh-keygen -q -t ed25519 -N '' -C test -f signing-key
        mkdir fake-bin
        printf '%s\n' '#!/bin/sh' 'echo "gh $*" >> "$(dirname "$0")/../gh.log"' '[ "$1 $2" != "release view" ]' > fake-bin/gh
        chmod +x fake-bin/gh
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
