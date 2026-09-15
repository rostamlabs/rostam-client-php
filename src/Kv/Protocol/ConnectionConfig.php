<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Kv\Protocol;

use InvalidArgumentException;

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
        public readonly Topology $topology = Topology::Unknown,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException on a `topology` that is not one of the declared values
     */
    public static function fromArray(array $config): self
    {
        $tls = $config['tls'] ?? false;
        $tls = is_array($tls) ? $tls : ['enabled' => (bool) $tls];

        $declared = $config['topology'] ?? Topology::Unknown;

        // Refused rather than defaulted. An unrecognised value would fall back to
        // the safe, slower path without a word, and whoever wrote `single_node`
        // meaning the fast one would never find out why it is not taken. The
        // enum itself is accepted too: casting it to a string is an Error.
        $topology = match (true) {
            $declared instanceof Topology => $declared,
            is_string($declared) && Topology::tryFrom($declared) !== null => Topology::from($declared),
            default => throw new InvalidArgumentException(sprintf(
                "unknown topology [%s]: expected '%s' (the default, safe on any server) or '%s'",
                is_scalar($declared) ? (string) $declared : get_debug_type($declared),
                Topology::Unknown->value,
                Topology::SingleNode->value,
            )),
        };

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
            topology: $topology,
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
