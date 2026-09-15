<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rostam\Exceptions\ProtocolException;
use Rostam\Kv\Metrics\KvMetrics;

/**
 * The parser a queue's safety check stands on.
 *
 * Its failure mode is not an exception - that is the good outcome - but a
 * counter read as absent or as zero when the server said something else. So
 * the real answer of a real server is the first fixture, and every way of
 * mis-reading a count is asserted to be refused rather than guessed.
 */
class KvMetricsTest extends TestCase
{
    /**
     * Captured from rostam v0.7.0-beta6, single node, `max_memory` 256MiB,
     * after 400 one-megabyte writes: 165 live records had been evicted, 235
     * keys remained, and nothing was ever refused.
     */
    private function realServer(): KvMetrics
    {
        return KvMetrics::fromPrometheusText(
            (string) file_get_contents(dirname(__DIR__).'/Fixtures/kv-metrics-v0.7.0-beta6.txt')
        );
    }

    public function test_it_reads_a_real_servers_answer(): void
    {
        $metrics = $this->realServer();

        $this->assertCount(33, $metrics->all());
        $this->assertSame(165, $metrics->evictionsLive());
        $this->assertSame(0, $metrics->rejects());
        $this->assertSame(235, $metrics->get(KvMetrics::ENTRIES));
    }

    /**
     * A metric a server does not report is null, never zero. A counter added
     * in a later release is simply absent from an older server's answer, and
     * reading that as "none happened" is the whole mistake to avoid.
     */
    public function test_absent_is_not_zero(): void
    {
        $metrics = KvMetrics::fromPrometheusText("rostam_kv_entries 3\n");

        $this->assertNull($metrics->evictionsLive());
        $this->assertNull($metrics->rejects());
        $this->assertFalse($metrics->has(KvMetrics::EVICTIONS_LIVE));
    }

    public function test_help_type_blank_lines_and_crlf_are_not_samples(): void
    {
        $metrics = KvMetrics::fromPrometheusText(
            "# HELP rostam_kv_rejects_total refused\r\n# TYPE rostam_kv_rejects_total counter\r\n\r\nrostam_kv_rejects_total 7\r\n"
        );

        $this->assertSame(['rostam_kv_rejects_total' => 7], $metrics->all());
    }

    public function test_a_labelled_series_is_kept_apart_from_the_unlabelled_sample(): void
    {
        $metrics = KvMetrics::fromPrometheusText(
            "rostam_kv_entries 10\nrostam_kv_entries{shard=\"0\"} 4\nrostam_kv_entries{shard=\"1\"} 6\n"
        );

        $this->assertSame(10, $metrics->get(KvMetrics::ENTRIES));
        $this->assertSame(['' => 10, '{shard="0"}' => 4, '{shard="1"}' => 6], $metrics->series(KvMetrics::ENTRIES));
    }

    /**
     * `{}` is an unlabelled sample written with braces. Reading it as a separate
     * series left get() answering null for a metric the server did report -
     * the exact mistake this class exists to prevent.
     */
    public function test_empty_braces_are_the_unlabelled_sample(): void
    {
        $metrics = KvMetrics::fromPrometheusText(KvMetrics::EVICTIONS_LIVE."{} 5\n");

        $this->assertSame(5, $metrics->evictionsLive());
    }

    /** A label value is a quoted string: a brace, a comma or an escaped quote inside it is data. */
    public function test_a_label_value_may_hold_braces_commas_and_escaped_quotes(): void
    {
        $metrics = KvMetrics::fromPrometheusText(
            'rostam_kv_entries{path="a}b,c",note="say \"hi\"",} 7'."\n"
        );

        $this->assertSame(['{note="say \"hi\"",path="a}b,c"}' => 7], $metrics->series(KvMetrics::ENTRIES));
    }

    /**
     * Blanks where Prometheus's text parser skips them - before the brace and
     * around every token inside it - are the same series written loosely.
     */
    public function test_blanks_inside_and_before_a_label_set_are_allowed(): void
    {
        $metrics = KvMetrics::fromPrometheusText(
            'rostam_kv_entries {a="1"} 5'."\n"
            .'rostam_kv_entries{ a = "2" , b="x y" , } 6'."\n"
            ."rostam_kv_entries\t{\tb\t=\t\"3\"\t} 7\n"
        );

        $this->assertSame(
            ['{a="1"}' => 5, '{a="2",b="x y"}' => 6, '{b="3"}' => 7],
            $metrics->series(KvMetrics::ENTRIES),
        );
        $this->assertFalse($metrics->has(KvMetrics::ENTRIES));
    }

    /** The same series in another label order is the same series, and a repeat is still refused. */
    public function test_a_series_repeated_in_another_label_order_is_refused(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/repeats the series/');

        KvMetrics::fromPrometheusText("rostam_kv_entries{a=\"1\",b=\"2\"} 1\nrostam_kv_entries{b=\"2\",a=\"1\"} 2\n");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedLabelSets(): array
    {
        return [
            'an unclosed value' => ['rostam_kv_entries{a="1} 5'],
            'no quotes' => ['rostam_kv_entries{a=1} 5'],
            'no separator' => ['rostam_kv_entries{a="1" b="2"} 5'],
            'the same label twice' => ['rostam_kv_entries{a="1",a="2"} 5'],
            'an unknown escape' => ['rostam_kv_entries{a="\q"} 5'],
            'a blank inside a label name' => ['rostam_kv_entries{a b="1"} 5'],
            'no value after the labels' => ['rostam_kv_entries {a="1"}'],
        ];
    }

    #[DataProvider('malformedLabelSets')]
    public function test_a_label_set_it_cannot_read_is_refused(string $line): void
    {
        $this->expectException(ProtocolException::class);

        KvMetrics::fromPrometheusText($line."\n");
    }

    public function test_a_trailing_timestamp_is_allowed(): void
    {
        $this->assertSame(5, KvMetrics::fromPrometheusText("rostam_kv_rejects_total 5 1726412345000\n")->rejects());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedAnswers(): array
    {
        return [
            'a line that is not a sample' => ["rostam_kv_entries\n"],
            'a value that is not a number' => ["rostam_kv_entries lots\n"],
            'an invalid metric name' => ["9rostam_kv_entries 1\n"],
            'the same series twice' => ["rostam_kv_entries 1\nrostam_kv_entries 2\n"],
        ];
    }

    #[DataProvider('malformedAnswers')]
    public function test_an_answer_it_cannot_read_is_refused(string $text): void
    {
        $this->expectException(ProtocolException::class);

        KvMetrics::fromPrometheusText($text);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function countersThatAreNotCounts(): array
    {
        return [
            // PHP casts NaN to 0: the one silent wrong answer, for the one
            // number a queue refuses to run on.
            'NaN' => ['NaN'],
            'positive infinity' => ['+Inf'],
            'negative' => ['-3'],
            'fractional' => ['2.5'],
        ];
    }

    #[DataProvider('countersThatAreNotCounts')]
    public function test_a_counter_that_is_not_a_count_is_refused_not_cast(string $value): void
    {
        $metrics = KvMetrics::fromPrometheusText(KvMetrics::EVICTIONS_LIVE." {$value}\n");

        $this->expectException(ProtocolException::class);

        $metrics->evictionsLive();
    }

    /**
     * The server's counters are uint64. One past PHP_INT_MAX must neither wrap
     * negative nor quietly become PHP_INT_MAX in the raw value - but as a count
     * it saturates, because "it happened" is what the caller needs to know.
     */
    public function test_a_counter_past_php_int_max_stays_exact_enough_and_saturates_as_a_count(): void
    {
        $metrics = KvMetrics::fromPrometheusText(KvMetrics::EVICTIONS_LIVE." 18446744073709551615\n");

        $this->assertIsFloat($metrics->get(KvMetrics::EVICTIONS_LIVE));
        $this->assertSame(PHP_INT_MAX, $metrics->evictionsLive());
    }

    public function test_php_int_max_itself_is_still_an_int(): void
    {
        $metrics = KvMetrics::fromPrometheusText(KvMetrics::EVICTIONS_LIVE.' '.PHP_INT_MAX."\n");

        $this->assertSame(PHP_INT_MAX, $metrics->get(KvMetrics::EVICTIONS_LIVE));
    }
}
