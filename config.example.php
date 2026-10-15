<?php
/**
 * Copy to config.php (same directory) and fill in. KEEP THIS FILE OUT OF GIT
 * once it holds your real secret: add config.php to .gitignore.
 *
 * Generate a strong secret with:  openssl rand -hex 32
 */

return array(
    // Must match the "Secret" you set on the GitHub webhook page.
    'secret' => 'CHANGE-ME-' . 'run: openssl rand -hex 32',

    // One entry per repo you want auto-deployed.
    'repos' => array(
        'Azanumer/my-site' => array(
            'branch'   => 'main',  // only deploys pushes to this branch ('' = any)
            'timeout'  => 120,     // seconds per command
            'commands' => array(
                // Prefer a deploy script over inline chains — easier to audit.
                '/opt/deploy/my-site.sh >> /var/log/deploy-my-site.log 2>&1',
            ),
        ),
    ),

    // Where deployment records (JSON lines) are written.
    'log_dir' => __DIR__ . '/storage',
);
