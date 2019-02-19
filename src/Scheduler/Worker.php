<?php

namespace Resque\Scheduler;

use \DateTime;
use \Resque\Event;
use \Resque\Scheduler;
use \Resque\Worker as ResqueWorker;

/**
 * ResqueScheduler worker to handle scheduling of delayed tasks.
 *
 * @package   ResqueScheduler
 * @author    Chris Boulton <chris@bigcommerce.com> (Original)
 * @author    Wan Qi Chen <kami@kamisama.me>
 * @copyright (c) 2012 Chris Boulton
 * @license   http://www.opensource.org/licenses/mit-license.php
 */
class Worker extends ResqueWorker
{
    /**
     * @var int Interval to sleep for between checking schedules.
     */
    protected $interval = 5;

    /**
     * The primary loop for a worker.
     *
     * Every $interval (seconds), the scheduled queue will be checked for jobs
     * that should be pushed to Resque.
     *
     * @param int $interval How often to check schedules.
     */
    public function work(int $interval = null)
    {
        if ($interval !== null) {
            $this->interval = $interval;
        }

        $this->updateProcLine('Starting');
        $this->startup();

        while (true) {
            if ($this->shutdown) {
                break;
            }

            $this->handleDelayedItems();
            $this->sleep();
        }

        $this->unregisterWorker();
    }

    /**
     * Handle delayed items for the next scheduled timestamp.
     *
     * Searches for any items that are due to be scheduled in Resque
     * and adds them to the appropriate job queue in Resque.
     */
    public function handleDelayedItems()
    {
        while ($timestamp = Scheduler::nextDelayedTimestamp()) {
            $this->updateProcLine('Processing Delayed Items');
            $this->enqueueDelayedItemsForTimestamp($timestamp);
        }
    }

    /**
     * Schedule all of the delayed jobs for a given timestamp.
     *
     * Searches for all items for a given timestamp, pulls them off the list of
     * delayed jobs and pushes them across to Resque.
     *
     * @param DateTime|int $timestamp Search for any items up to this timestamp
     *      to schedule.
     */
    public function enqueueDelayedItemsForTimestamp($timestamp)
    {
        $item = null;

        while ($item = Scheduler::nextItemForTimestamp($timestamp)) {
            $this->log([
                'message' => 'Moving scheduled job ' . strtoupper($item['class']) . ' to ' . strtoupper($item['queue']),
                'data' => [
                    'type' => 'movescheduled',
                    'args' => [
                        'timestamp' => $timestamp,
                        'class' => $item['class'],
                        'queue' => $item['queue'],
                        'job_id' => $item['args'][0]['id'],
                        'wait' => round(microtime(true) - (isset($item['s_time']) ? $item['s_time'] : 0), 3),
                        's_wait' => $timestamp - floor(isset($item['s_time']) ? $item['s_time'] : 0)
                    ]
                ]
            ], self::LOG_TYPE_INFO);

            Event::trigger('beforeDelayedEnqueue', [
                'queue' => $item['queue'],
                'class' => $item['class'],
                'args'  => $item['args'],
            ]);

            $payload = array_merge([$item['queue'], $item['class']],
                $item['args'], [$item['track']]);

            call_user_func_array('\\Resque\\Resque::enqueue', $payload);
        }
    }

    /**
     * Sleep for the defined interval.
     */
    protected function sleep()
    {
        $this->log([
            'message' => 'Sleeping for ' . $this->interval,
            'data' => [
                'type' => 'sleep',
                'second' => $this->interval
            ]
        ], self::LOG_TYPE_DEBUG);

        sleep($this->interval);
    }

    /**
     * Update the status of the current worker process.
     *
     * @param string $status The updated process title.
     */
    protected function updateProcLine(string $status)
    {
        cli_set_process_title('resque-scheduler-' . Scheduler::VERSION
            . ': ' . $status);
    }
}
