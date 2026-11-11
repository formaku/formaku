<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use LogicException;

final class MerchantRuntime
{
    public static function configure(Repository $config): void
    {
        $id = $config->get('merchant.id');
        $host = $config->get('merchant.host');
        if ($id === null && $host === null) {
            return;
        }

        if (! is_string($id) || ! Str::isUuid($id) || ! is_string($host)
            || ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host)) {
            throw new LogicException('Managed Formaku requires a merchant UUID and a canonical DNS hostname.');
        }

        $database = 'formaku_'.str_replace('-', '', strtolower($id));
        $driver = $config->get('database.default');
        $connection = $config->get('database.connections.'.$driver);
        $validDatabase = $driver === 'pgsql'
            && ($connection['database'] ?? null) === $database
            && ($connection['username'] ?? null) === $database
            && ! empty($connection['password']);
        if ($config->get('app.env') === 'testing' && $driver === 'sqlite') {
            $validDatabase = basename($connection['database'] ?? '') === $database.'.sqlite';
        }
        if (! $validDatabase || ! empty($connection['url']) || (isset($connection['read']) || isset($connection['write']))
            || empty($config->get('app.key'))) {
            throw new LogicException('Managed Formaku requires a dedicated merchant database, role and application key.');
        }

        $root = storage_path('app/merchants/'.$database);
        $config->set([
            'app.url' => 'https://'.$host,
            'session.driver' => 'database',
            'session.connection' => $driver,
            'session.table' => 'sessions',
            'session.cookie' => '__Host-'.$database,
            'session.domain' => null,
            'session.path' => '/',
            'session.secure' => true,
            'session.http_only' => true,
            'cache.default' => 'database',
            'cache.limiter' => 'database',
            'cache.stores.database.connection' => $driver,
            'cache.stores.database.lock_connection' => $driver,
            'cache.prefix' => $database.':',
            'queue.default' => 'database',
            'queue.connections.database.connection' => $driver,
            'queue.batching.database' => $driver,
            'queue.failed.database' => $driver,
            'filesystems.default' => 'local',
            'filesystems.disks.local.root' => $root.'/private',
            'filesystems.disks.public.root' => $root.'/public',
            'filesystems.disks.public.url' => null,
            'filesystems.links' => [],
        ]);
    }
}
