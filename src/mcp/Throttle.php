<?php
namespace OcMcp;

/**
 * Keeps bulk work gentle on shared hosting: the database may be busy at most $duty of the wall time (default 80 %),
 * the rest is idle (usleep), so the shop's own visitors keep getting answers. Also stops a call before PHP's
 * max_execution_time: the caller returns what was done and where to continue.
 */
final class Throttle {
    /** @var float */
    private $duty;
    /** @var float */
    private $budget;
    /** @var float */
    private $start;
    /** @var float */
    private $busy = 0.0;
    /** @var float */
    private $slept = 0.0;

    public function __construct(float $duty = 0.8, float $budgetSeconds = 20.0) {
        $this->duty = max(0.1, min(1.0, $duty));
        $this->budget = $budgetSeconds;
        $this->start = microtime(true);
    }

    /** Run one unit of database work, then pause if the database has been busier than the duty allows. */
    public function run(callable $fn) {
        $t = microtime(true);

        try {
            return $fn();
        } finally {
            $this->busy += microtime(true) - $t;
            $this->pace();
        }
    }

    private function pace(): void {
        if ($this->duty >= 1.0) {
            return;
        }

        $elapsed = microtime(true) - $this->start;
        $needed = $this->busy / $this->duty - $elapsed; // idle time that brings busy / elapsed back to the duty

        if ($needed > 0.002) {
            $sleep = min($needed, 1.0);
            usleep((int)round($sleep * 1000000));
            $this->slept += $sleep;
        }
    }

    /** True once the time budget of this call is used up (leave room to answer). */
    public function outOfTime(): bool {
        return microtime(true) - $this->start >= $this->budget;
    }

    public function stats(): array {
        $elapsed = microtime(true) - $this->start;

        return ['elapsed_ms' => (int)round($elapsed * 1000), 'db_busy_ms' => (int)round($this->busy * 1000), 'paused_ms' => (int)round($this->slept * 1000),
                'db_load_percent' => $elapsed > 0 ? (int)round($this->busy / $elapsed * 100) : 0];
    }
}
