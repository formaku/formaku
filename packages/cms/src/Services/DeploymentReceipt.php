<?php

namespace Formaku\Cms\Services;

use ZipArchive;

class DeploymentReceipt
{
    public function __construct(private GitHubActionsArtifact $artifacts) {}

    public function read(string $repository, string $token, int $runId, string $release, string $image): array
    {
        abort_unless(class_exists(ZipArchive::class), 503, 'Deployment receipts require the PHP ZIP extension.');

        $actions = 'https://api.github.com/repos/'.$repository.'/actions';
        $runResponse = $this->artifacts->github($token, $actions.'/runs/'.$runId);
        abort_if($runResponse->notFound(), 404);
        abort_unless($runResponse->successful(), 502, 'GitHub deployment status is temporarily unavailable.');
        $run = $runResponse->json();

        abort_unless(
            is_array($run)
            && ($run['id'] ?? null) === $runId
            && ($run['event'] ?? null) === 'workflow_dispatch'
            && ($run['head_branch'] ?? null) === 'main'
            && ($run['path'] ?? null) === '.github/workflows/deploy.yml'
            && ($run['display_title'] ?? null) === 'Formaku deploy '.$release,
            404,
        );

        $attempt = $run['run_attempt'] ?? null;
        $status = $run['status'] ?? null;
        abort_unless(
            is_int($attempt) && $attempt > 0
            && in_array($status, ['queued', 'requested', 'waiting', 'pending', 'in_progress', 'completed'], true),
            502,
            'GitHub returned an invalid deployment status.',
        );
        $result = [
            'state' => 'pending',
            'run_id' => $runId,
            'run_attempt' => $attempt,
            'run_url' => 'https://github.com/'.$repository.'/actions/runs/'.$runId.'/attempts/'.$attempt,
            'receipt' => null,
        ];
        if ($status !== 'completed') {
            return $result;
        }

        $artifact = $this->artifacts->read($actions, $token, $runId, 'formaku-deployment-'.$attempt, 'formaku-deployment.json');
        $receipt = $this->validateReceipt($artifact, $release, $image);

        return array_replace($result, [
            'state' => $receipt === null ? 'unavailable' : 'verified',
            'receipt' => $receipt,
        ]);
    }

    private function validateReceipt(mixed $receipt, string $release, string $image): ?array
    {
        $exit = $receipt['ssh_exit_code'] ?? null;
        if (! is_array($receipt) || ($receipt['format'] ?? null) !== 1 || ($receipt['release'] ?? null) !== $release
            || ($receipt['image'] ?? null) !== $image || ! is_int($exit) || $exit < 0 || $exit > 255
            || ! array_key_exists('host_status', $receipt)) {
            return null;
        }

        $host = $receipt['host_status'];
        $hostStatus = null;
        if ($host !== null) {
            if (! is_array($host) || ! array_key_exists('rollback', $host) || ! array_key_exists('backup', $host)) {
                return null;
            }
            $state = $host['state'] ?? null;
            $rollback = $host['rollback'];
            $backup = $host['backup'];
            if (! in_array($state, ['deploying', 'deployed', 'failed'], true)
                || ($state === 'failed' && ! in_array($rollback, ['not_attempted', 'restored', 'failed', 'unavailable'], true))
                || ($state !== 'failed' && $rollback !== null)
                || ($backup !== null && ($state !== 'failed' || $backup !== $release))) {
                return null;
            }
            $hostStatus = ['state' => $state, 'rollback' => $rollback, 'backup' => $backup];
        }

        return ['format' => 1, 'release' => $release, 'image' => $image, 'ssh_exit_code' => $exit, 'host_status' => $hostStatus];
    }
}
