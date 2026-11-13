<?php

namespace Formaku\Cms\Services;

use DateTimeImmutable;
use DateTimeZone;
use ZipArchive;

class RuntimeObservation
{
    private const WORKFLOW = 'runtime-status.yml';

    public function __construct(private GitHubActionsArtifact $artifacts) {}

    public static function workflowUrl(mixed $repository): ?string
    {
        return GitHubActionsArtifact::validRepository($repository)
            ? 'https://github.com/'.$repository.'/actions/workflows/'.self::WORKFLOW
            : null;
    }

    public function read(string $repository, string $token): array
    {
        abort_unless(class_exists(ZipArchive::class), 503, 'Runtime observations require the PHP ZIP extension.');

        $actions = 'https://api.github.com/repos/'.$repository.'/actions';
        $list = $this->artifacts->github($token, $actions.'/workflows/'.self::WORKFLOW.'/runs', [
            'branch' => 'main', 'event' => 'workflow_dispatch', 'per_page' => 1,
        ]);
        abort_unless($list->successful(), 502, 'GitHub runtime status is temporarily unavailable.');
        $runs = $list->json('workflow_runs');
        $total = $list->json('total_count');
        abort_unless(is_int($total) && $total >= 0 && is_array($runs) && array_is_list($runs)
            && count($runs) === min(1, $total), 502, 'GitHub returned an invalid runtime run list.');
        if ($runs === []) {
            return ['state' => 'none'];
        }

        $latest = $runs[0];
        $runId = $latest['id'] ?? null;
        $attempt = $latest['run_attempt'] ?? null;
        abort_unless(is_int($runId) && $runId > 0 && is_int($attempt) && $attempt > 0
            && $this->matchesRun($latest, $repository), 502, 'GitHub returned an unexpected runtime run.');

        $response = $this->artifacts->github($token, $actions.'/runs/'.$runId.'/attempts/'.$attempt);
        abort_unless($response->successful(), 502, 'GitHub runtime status is temporarily unavailable.');
        $run = $response->json();
        $status = $run['status'] ?? null;
        abort_unless($this->matchesRun($run, $repository) && ($run['id'] ?? null) === $runId
            && ($run['run_attempt'] ?? null) === $attempt
            && in_array($status, ['queued', 'requested', 'waiting', 'pending', 'in_progress', 'completed'], true),
            502, 'GitHub returned an unexpected runtime attempt.');

        $result = [
            'state' => 'pending',
            'run_id' => $runId,
            'run_attempt' => $attempt,
            'run_url' => 'https://github.com/'.$repository.'/actions/runs/'.$runId.'/attempts/'.$attempt,
        ];
        if ($status !== 'completed') {
            return $result;
        }

        $artifact = $this->artifacts->read($actions, $token, $runId, 'formaku-runtime-'.$attempt,
            'formaku-runtime.json', requireDigest: true);
        $observation = $this->validateObservation($artifact, $runId, $attempt);
        if ($observation === null) {
            return array_replace($result, ['state' => 'unavailable']);
        }

        return array_replace($result, ['state' => 'observed', 'observation' => $observation]);
    }

    private function matchesRun(mixed $run, string $repository): bool
    {
        $runRepository = $run['repository']['full_name'] ?? null;

        return is_array($run) && is_string($runRepository) && strcasecmp($runRepository, $repository) === 0
            && ($run['path'] ?? null) === '.github/workflows/'.self::WORKFLOW
            && ($run['event'] ?? null) === 'workflow_dispatch'
            && ($run['head_branch'] ?? null) === 'main';
    }

    private function validateObservation(mixed $artifact, int $runId, int $attempt): ?array
    {
        $timestamp = $artifact['recorded_at'] ?? null;
        $host = $artifact['host'] ?? null;
        $state = $host['state'] ?? null;
        if (! is_array($artifact) || count($artifact) !== 5 || ($artifact['format'] ?? null) !== 1
            || ($artifact['run_id'] ?? null) !== $runId || ($artifact['run_attempt'] ?? null) !== $attempt
            || ! is_string($timestamp) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $timestamp)
            || ! is_array($host) || ! in_array($state, ['verified', 'transitioning', 'mismatch', 'unavailable'], true)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $timestamp, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $timestamp) {
            return null;
        }

        $image = $host['image'] ?? null;
        if ($state === 'verified') {
            if (count($host) !== 2 || ! is_string($image)
                || ! preg_match('~\Aghcr\.io/[a-z0-9_.-]+/[a-z0-9_.-]+@sha256:[a-f0-9]{64}\z~', $image)) {
                return null;
            }
        } elseif (count($host) !== 1) {
            return null;
        }

        return ['recorded_at' => $timestamp, 'host' => $host];
    }
}
