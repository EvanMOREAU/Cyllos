<?php

namespace App\Deployment;

use Symfony\Component\Process\Process;

/**
 * Runs a fixed, whitelisted sequence of deploy commands — never arbitrary
 * shell input. This is deliberately narrow: it exists so a CEO can trigger
 * "pull latest + apply migrations + clear cache" from the UI, not so the
 * app can execute anything else.
 *
 * Security note: letting an authenticated web action rewrite the app's own
 * source code and run new code from it is a real elevation-of-privilege
 * surface (a compromised CEO session/credential becomes a compromised
 * server). It's accepted here as a deliberate, informed trade-off for a
 * small internal tool — see the "Incidents résolus" / deployment section of
 * the documentation. Each step still runs a fixed binary with fixed
 * arguments (no string concatenation from user input), and the whole thing
 * is gated behind ROLE_CEO specifically (not just ROLE_DEVELOPER).
 *
 * Concurrency: guarded by an exclusive lock on var/deploy.lock so two clicks
 * (or a click during a scheduled deploy) can't run git pull / composer /
 * migrations on top of each other.
 */
class DeploymentRunner
{
    private const TIMEOUT_SECONDS = 300.0;

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{
     *     success: bool,
     *     locked: bool,
     *     steps: array<int, array{label: string, command: string, exitCode: ?int, output: string, optional?: bool}>
     * }
     */
    public function run(): array
    {
        $lockHandle = @fopen($this->projectDir . '/var/deploy.lock', 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (\is_resource($lockHandle)) {
                fclose($lockHandle);
            }

            return ['success' => false, 'locked' => true, 'steps' => []];
        }

        try {
            return $this->runSteps();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * @return array{success: bool, locked: bool, steps: array<int, array<string, mixed>>}
     */
    private function runSteps(): array
    {
        $steps = [
            ['label' => 'Récupération du code (git pull)', 'command' => ['git', 'pull', '--ff-only']],
            ['label' => 'Installation des dépendances (composer install)', 'command' => ['composer', 'install', '--no-interaction']],
            ['label' => 'Application des migrations', 'command' => ['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']],
            ['label' => 'Vidage du cache', 'command' => ['php', 'bin/console', 'cache:clear', '--no-interaction']],
            // Recompile AssetMapper's manifest — without this, a deploy that ships a
            // *new* JS/CSS file leaves prod silently serving the old manifest, which
            // doesn't know the new file exists (a changed existing file mostly still
            // works via cache:clear alone, which is why this went unnoticed until the
            // first PR to add brand new controllers). See "Incidents résolus".
            ['label' => 'Compilation des assets (AssetMapper)', 'command' => ['php', 'bin/console', 'asset-map:compile', '--no-interaction']],
            // Best-effort: reload the workers so they pick up the new message-handler
            // code. Needs a passwordless sudoers rule (see the deployment doc); if it
            // is absent this step reports "à faire manuellement" without failing the
            // deploy — the code itself is already live.
            ['label' => 'Redémarrage des workers', 'command' => ['sudo', '-n', 'systemctl', 'restart', 'cyllos-worker', 'cyllos-scheduler'], 'optional' => true],
        ];

        $results = [];
        $success = true;

        foreach ($steps as $step) {
            $optional = $step['optional'] ?? false;

            if (!$success && !$optional) {
                $results[] = ['label' => $step['label'], 'command' => implode(' ', $step['command']), 'exitCode' => null, 'output' => '(ignoré — étape précédente en échec)'];

                continue;
            }

            $process = new Process($step['command'], $this->projectDir, null, null, self::TIMEOUT_SECONDS);
            $process->run();

            $output = trim($process->getOutput() . $process->getErrorOutput());
            if ($optional && !$process->isSuccessful()) {
                $output = "Redémarrage automatique indisponible (pas de règle sudo). À faire manuellement :\n  sudo systemctl restart cyllos-worker cyllos-scheduler\n\n" . $output;
            }

            $results[] = [
                'label' => $step['label'],
                'command' => implode(' ', $step['command']),
                'exitCode' => $process->getExitCode(),
                'output' => $output,
                'optional' => $optional,
            ];

            if (!$process->isSuccessful() && !$optional) {
                $success = false;
            }
        }

        return ['success' => $success, 'locked' => false, 'steps' => $results];
    }
}
