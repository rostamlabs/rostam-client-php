<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Kv\Metrics;

use Rostam\Exceptions\ProtocolException;

/**
 * A snapshot of one node's key-value counters, as `__kv_metrics__` reports them.
 *
 * The server answers in the Prometheus text exposition format. That is parsed
 * strictly: a sample line this class cannot read is a {@see ProtocolException},
 * never a line quietly skipped. The strictness is the point. Callers make
 * safety decisions on these numbers - a queue refusing to run on a server that
 * has evicted live data reads `evictions_live_total` - and a counter that is
 * silently missing reads as the absence of the thing it counts.
 *
 * Every value is node-wide and cumulative since the server started.
 */
final class KvMetrics
{
    /** Live records displaced by eviction: lost to capacity, not to their TTL. */
    public const EVICTIONS_LIVE = 'rostam_kv_evictions_live_total';

    /** Writes refused because the shard was full and does not evict. */
    public const REJECTS = 'rostam_kv_rejects_total';

    /** Keys currently held in the index. */
    public const ENTRIES = 'rostam_kv_entries';

    private const NAME = '/\G[a-zA-Z_:][a-zA-Z0-9_:]*/';

    private const LABEL_NAME = '/\G[a-zA-Z_][a-zA-Z0-9_]*/';

    private const VALUE_AND_TIMESTAMP = '/\G[ \t]+(\S+)(?:[ \t]+-?\d+)?$/';

    /**
     * @param  array<string, array<string, int|float>>  $series  name => (label set => value); '' is the unlabelled sample
     */
    private function __construct(private readonly array $series) {}

    /**
     * @throws ProtocolException on a sample line that is not in the format
     */
    public static function fromPrometheusText(string $text): self
    {
        $series = [];

        foreach (preg_split('/\r?\n/', $text) ?: [] as $number => $raw) {
            $line = trim($raw);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            [$name, $labels, $value] = self::sample($line, $number + 1);

            if (isset($series[$name][$labels])) {
                throw new ProtocolException(sprintf('kv metrics line %d repeats the series %s%s', $number + 1, $name, $labels));
            }

            $series[$name][$labels] = self::value($value, $number + 1);
        }

        return new self($series);
    }

    /**
     * One sample line: a name, an optional label set, a value, an optional timestamp.
     *
     * The label set is scanned, not matched with a pattern, because a label
     * value is a quoted string and may hold any of `}`, `,` and an escaped
     * quote. It comes back in a canonical form - labels sorted by name - so
     * the same series written in another order is still caught as a repeat,
     * and an empty `{}` is the unlabelled sample it means rather than a
     * separate series nobody would look up.
     *
     * @return array{0: string, 1: string, 2: string} name, canonical label set, raw value
     */
    private static function sample(string $line, int $number): array
    {
        $malformed = static fn (string $why) => new ProtocolException(
            sprintf('kv metrics line %d is not a Prometheus sample (%s): %s', $number, $why, $line)
        );

        if (preg_match(self::NAME, $line, $match) !== 1) {
            throw $malformed('no metric name');
        }

        $name = $match[0];
        $at = strlen($name);
        $labels = [];

        if (($line[$at] ?? '') === '{') {
            $at++;

            while (true) {
                $at += strspn($line, " \t", $at);

                if (($line[$at] ?? '') === '}') {
                    $at++;

                    break;
                }

                if (preg_match(self::LABEL_NAME, $line, $match, 0, $at) !== 1) {
                    throw $malformed('a label without a name');
                }

                $label = $match[0];
                $at += strlen($label);

                if (($line[$at] ?? '') !== '=' || ($line[$at + 1] ?? '') !== '"') {
                    throw $malformed("label {$label} is not name=\"value\"");
                }

                $at += 2;
                $value = '';

                while (true) {
                    $char = $line[$at] ?? null;

                    if ($char === null) {
                        throw $malformed("label {$label} is never closed");
                    }

                    $at++;

                    if ($char === '"') {
                        break;
                    }

                    if ($char === '\\') {
                        $escaped = $line[$at] ?? null;
                        $at++;
                        $value .= match ($escaped) {
                            'n' => "\n",
                            '\\', '"' => $escaped,
                            default => throw $malformed("label {$label} has an unknown escape"),
                        };

                        continue;
                    }

                    $value .= $char;
                }

                if (array_key_exists($label, $labels)) {
                    throw $malformed("label {$label} appears twice");
                }

                $labels[$label] = $value;
                $at += strspn($line, " \t", $at);

                if (($line[$at] ?? '') === ',') {
                    $at++;
                } elseif (($line[$at] ?? '') !== '}') {
                    throw $malformed('labels are not separated by commas');
                }
            }
        }

        if (preg_match(self::VALUE_AND_TIMESTAMP, $line, $match, 0, $at) !== 1) {
            throw $malformed('no value');
        }

        return [$name, self::canonical($labels), $match[1]];
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function canonical(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        ksort($labels, SORT_STRING);

        $pairs = [];

        foreach ($labels as $label => $value) {
            $pairs[] = $label.'="'.addcslashes($value, "\\\"\n").'"';
        }

        return '{'.implode(',', $pairs).'}';
    }

    public function has(string $name): bool
    {
        return isset($this->series[$name]['']);
    }

    /**
     * The unlabelled sample for a metric, or null when the server did not report it.
     *
     * Null is not zero. A metric added in a later release is absent from an
     * older server's answer, and treating that as "none happened" is exactly
     * the mistake this class exists to prevent.
     */
    public function get(string $name): int|float|null
    {
        return $this->series[$name][''] ?? null;
    }

    /**
     * Every labelled and unlabelled sample for one metric, keyed by its label set.
     *
     * @return array<string, int|float>
     */
    public function series(string $name): array
    {
        return $this->series[$name] ?? [];
    }

    /**
     * Every unlabelled sample.
     *
     * @return array<string, int|float>
     */
    public function all(): array
    {
        $samples = [];

        foreach ($this->series as $name => $sets) {
            if (array_key_exists('', $sets)) {
                $samples[$name] = $sets[''];
            }
        }

        return $samples;
    }

    /**
     * Live records this node has lost to capacity, or null on a server that
     * does not count them.
     */
    public function evictionsLive(): ?int
    {
        return $this->count(self::EVICTIONS_LIVE);
    }

    /**
     * Writes this node refused at capacity, or null on a server that does not
     * count them.
     */
    public function rejects(): ?int
    {
        return $this->count(self::REJECTS);
    }

    /**
     * A counter as a non-negative integer, or null when it was not reported.
     *
     * The cast is where a safety check would go wrong without a sound. PHP
     * turns NaN into 0, so a counter the server sent as NaN would read as
     * "nothing happened". A counter is a whole, finite, non-negative number or
     * the answer is malformed, and it is refused as such - NaN and infinity
     * included. One finite but too large for an int saturates: it still says
     * the thing happened, which is what matters.
     *
     * @throws ProtocolException on a counter that is not a count
     */
    public function count(string $name): ?int
    {
        $value = $this->get($name);

        if ($value === null || is_int($value)) {
            if (is_int($value) && $value < 0) {
                throw new ProtocolException("kv metric {$name} is a counter but reads {$value}");
            }

            return $value;
        }

        if (! is_finite($value) || $value < 0 || floor($value) !== $value) {
            throw new ProtocolException("kv metric {$name} is a counter but reads {$value}");
        }

        return $value >= PHP_INT_MAX ? PHP_INT_MAX : (int) $value;
    }

    private static function value(string $raw, int $line): int|float
    {
        return match (true) {
            $raw === 'NaN' => NAN,
            $raw === '+Inf' => INF,
            $raw === '-Inf' => -INF,
            // Counters are uint64 on the server. filter_var refuses a value
            // outside PHP's int range instead of saturating it, so one past
            // PHP_INT_MAX stays an exact-enough float rather than quietly
            // becoming PHP_INT_MAX through a string cast.
            preg_match('/^-?\d+$/', $raw) === 1 => filter_var($raw, FILTER_VALIDATE_INT) !== false
                ? (int) $raw
                : (float) $raw,
            is_numeric($raw) => (float) $raw,
            default => throw new ProtocolException(sprintf('kv metrics line %d carries a value that is not a number: %s', $line, $raw)),
        };
    }
}
