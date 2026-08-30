<?php

declare(strict_types=1);

namespace Rostam\Kv\Protocol;

/**
 * Everything needed to open a socket to a Rostam server's -tcp listener.
 */
final class ConnectionConfig
{
    /**
     * @param  array<string, mixed>  $sslOptions  stream context options for the "ssl" wrapper
     */
    public function __construct(
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 7000,
        public readonly string $token = '',
        public readonly float $connectTimeout = 2.0,
        public readonly float $timeout = 5.0,
        public readonly bool $persistent = false,
        public readonly bool $tls = false,
        public readonly array $sslOptions = [],
        public readonly int $poolSize = 4,
        public readonly bool $retryOnStaleConnection = true,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $tls = $config['tls'] ?? false;
        $tls = is_array($tls) ? $tls : ['enabled' => (bool) $tls];

        return new self(
            host: (string) ($config['host'] ?? '127.0.0.1'),
            port: (int) ($config['port'] ?? 7000),
            token: (string) ($config['token'] ?? ''),
            connectTimeout: (float) ($config['connect_timeout'] ?? 2.0),
            timeout: (float) ($config['timeout'] ?? 5.0),
            persistent: (bool) ($config['persistent'] ?? false),
            tls: (bool) ($tls['enabled'] ?? false),
            sslOptions: self::sslOptionsFrom($tls),
            poolSize: max(1, (int) ($config['pool_size'] ?? 4)),
            retryOnStaleConnection: (bool) ($config['retry_on_stale_connection'] ?? true),
        );
    }

    public function uri(): string
    {
        $host = str_contains($this->host, ':') && ! str_starts_with($this->host, '[')
            ? '['.$this->host.']'
            : $this->host;

        return ($this->tls ? 'tls' : 'tcp').'://'.$host.':'.$this->port;
    }

    /**
     * @param  array<string, mixed>  $tls
     * @return array<string, mixed>
     */
    private static function sslOptionsFrom(array $tls): array
    {
        $options = array_filter([
            'verify_peer' => $tls['verify_peer'] ?? true,
            'verify_peer_name' => $tls['verify_peer_name'] ?? true,
            'cafile' => $tls['ca'] ?? null,
            'local_cert' => $tls['cert'] ?? null,
            'local_pk' => $tls['key'] ?? null,
            'peer_name' => $tls['peer_name'] ?? null,
        ], static fn ($value) => $value !== null);

        return $options + ($tls['options'] ?? []);
    }
}
