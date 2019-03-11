<?php

namespace Resque;

use \DateTime;
use \Resque\Event;
use \Resque\Resque;
use \Resque\ResqueException;
use \Resque\Scheduler\InvalidTimestampException;
use \Resque\Scheduler\Job\Status;

/**
 * ResqueScheduler core class to handle scheduling of jobs in the future.
 *
 * @package   ResqueScheduler
 * @author    Chris Boulton <chris@bigcommerce.com> (Original)
 * @author    Wan Qi Chen <kami@kamisama.me>
 * @copyright (c) 2012 Chris Boulton
 * @license   http://www.opensource.org/licenses/mit-license.php
 */
class Scheduler
{
    const VERSION = "2.1.0";

    // Name of the scheduler queue
    // Should be as unique as possible
    const QUEUE_NAME = '_schdlr_';

    /**
     * Enqueue a job in a given number of seconds from now.
     *
     * Identical to Resque::enqueue, however the first argument is the number
     * of seconds before the job should be executed.
     *
     * @param int $in Number of seconds from now when the job should be
     *      executed.
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to
     *      execute the job.
     * @param mixed[] $args Any optional arguments that should be passed when
     *      the job is executed.
     * @param bool $trackStatus Set to true to be able to monitor the status of
     *      the job.
     * @return string Job ID
     */
    public static function enqueueIn(
        int $in,
        string $queue,
        string $class,
        array $args = [],
        bool $trackStatus = false
    ) : string {
        return self::enqueueAt(time() + $in, $queue, $class, $args,
            $trackStatus);
    }

    /**
     * Enqueue a job for execution at a given timestamp.
     *
     * Identical to Resque::enqueue, however the first argument is a timestamp
     * (either UNIX timestamp in integer format or an instance of the DateTime
     * class in PHP).
     *
     * @param DateTime|int $at Instance of PHP DateTime object or int of UNIX
     *      timestamp.
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to
     *      execute the job.
     * @param mixed[] $args Any optional arguments that should be passed when
     *      the job is executed.
     * @param bool $trackStatus Set to true to be able to monitor the status of
     *      the job.
     * @return string Job ID
     */
    public static function enqueueAt(
        $at,
        string $queue,
        string $class,
        array $args = [],
        bool $trackStatus = false
    ) : string {
        self::validateJob($class, $queue);

        $args['id'] = Resque::generateJobId();
        $args['s_time'] = time();
        $job = self::jobToHash($queue, $class, $args, $trackStatus);
        self::delayedPush($at, $job);

        if ($trackStatus) {
            Status::create($args['id'], Status::STATUS_SCHEDULED);
        }

        Event::trigger('afterSchedule', [
            'at'    => $at,
            'queue' => $queue,
            'class' => $class,
            'args'  => $args,
            'id'    => $args['id'],
        ]);

        return $args['id'];
    }

    /**
     * Directly append an item to the delayed queue schedule.
     *
     * @param DateTime|int $timestamp Timestamp job is scheduled to be run at.
     * @param mixed[] $item Hash of item to be pushed to schedule.
     */
    public static function delayedPush($timestamp, array $item)
    {
        $json = json_encode($item, Resque::JSON_ENCODE_OPTIONS);

        if ($json !== false) { // TODO or throw?
            $timestamp = self::getTimestamp($timestamp);
            $redis = Resque::redis();
            $redis->rpush(self::QUEUE_NAME . ':' . $timestamp, $json);
            $redis->zadd(self::QUEUE_NAME, [$timestamp => $timestamp]);
        }
    }

    /**
     * Get the total number of jobs in the delayed schedule.
     *
     * @return int Number of scheduled jobs.
     */
    public static function getDelayedQueueScheduleSize() : int
    {
        return Resque::redis()->zcard(self::QUEUE_NAME);
    }

    /**
     * Get the number of jobs for a given timestamp in the delayed schedule.
     *
     * @param DateTime|int $timestamp Timestamp
     * @return int Number of scheduled jobs.
     */
    public static function getDelayedTimestampSize($timestamp)
    {
        $timestamp = self::getTimestamp($timestamp);

        return Resque::redis()->llen(self::QUEUE_NAME . ':' . $timestamp);
    }

    /**
     * Remove a delayed job from the queue.
     *
     * note: you must specify exactly the same
     * queue, class and arguments that you used when you added
     * to the delayed queue
     *
     * also, this is an expensive operation because all delayed keys have to be
     * searched
     *
     * @param string $queue A queue name.
     * @param string $class A job class name.
     * @param mixed[] $args Some job arguments.
     * @return int The number of jobs that were removed.
     */
    public static function removeDelayed(
        string $queue,
        string $class,
        array $args
    ) : int {
        $job = self::jobToHash($queue, $class, $args);
        $json = json_encode($job, Resque::JSON_ENCODE_OPTIONS);
        $destroyed = 0;

        if ($json !== false) { // TODO or throw?
            $redis = Resque::redis();

            foreach ($redis->keys(self::QUEUE_NAME . ':*') as $key) {
                $key = $redis->removePrefix($key);
                $destroyed += $redis->lrem($key, 0, $json);
            }
        }

        return $destroyed;
    }

    /**
     * removed a delayed job queued for a specific timestamp
     *
     * note: you must specify exactly the same
     * queue, class and arguments that you used when you added
     * to the delayed queue
     *
     * @param DateTime|int $timestamp A timestamp.
     * @param string $queue A queue name.
     * @param string $class A job class name.
     * @param mixed[] $args Some job arguments.
     * @return int The number of jobs that were removed.
     */
    public static function removeDelayedJobFromTimestamp(
        $timestamp,
        string $queue,
        string $class,
        array $args
    ) : int {
        $job = self::jobToHash($queue, $class, $args);
        $json = json_encode($job, Resque::JSON_ENCODE_OPTIONS);

        if ($json === false) {
            return 0;
        }

        $timestamp = self::getTimestamp($timestamp);
        $key = self::QUEUE_NAME . ':' . $timestamp;
        $redis = Resque::redis();
        $count = $redis->lrem($key, 0, $json);
        self::cleanupTimestamp($key, $timestamp);

        return $count;
    }

    /**
     * Generate hash of all job properties to be saved in the scheduled queue.
     *
     * @param string $queue Name of the queue the job will be placed on.
     * @param string $class Name of the job class.
     * @param mixed[] $args Array of job arguments.
     * @param bool $trackStatus Whether to track the job status.
     * @return mixed[] The job properties.
     */

    private static function jobToHash(
        string $queue,
        string $class,
        array $args = [],
        bool $trackStatus = false
    ) : array {
        return [
            'class' => $class,
            'args'  => [$args],
            'queue' => $queue,
            'track' => $trackStatus,
        ];
    }

    /**
     * If there are no jobs for a given key/timestamp, delete references to it.
     *
     * Used internally to remove empty delayed: items in Redis when there are
     * no more jobs left to run at that timestamp.
     *
     * @param string $key Key to count number of items at.
     * @param DateTime|int $timestamp Matching timestamp for $key.
     */
    private static function cleanupTimestamp($key, $timestamp)
    {
        $timestamp = self::getTimestamp($timestamp);
        $redis = Resque::redis();

        if ($redis->llen($key) == 0) {
            $redis->del($key);
            $redis->zrem(self::QUEUE_NAME, (string)$timestamp);
        }
    }

    /**
     * Convert a timestamp in some format in to a unix timestamp as an integer.
     *
     * @param DateTime|int $timestamp Instance of DateTime or UNIX timestamp.
     * @return int UNIX timestamp
     * @throws InvalidTimestampException
     */
    private static function getTimestamp($timestamp)
    {
        if ($timestamp instanceof DateTime) {
            $timestamp = $timestamp->getTimestamp();
        }

        if ((int)$timestamp != $timestamp) {
            throw new InvalidTimestampException(
                'The supplied timestamp value could not be converted to an integer.'
            );
        }

        return (int)$timestamp;
    }

    /**
     * Find the first timestamp in the delayed schedule before/including the
     * timestamp.
     *
     * Will find and return the first timestamp upto and including the given
     * timestamp. This is the heart of the ResqueScheduler that will make sure
     * that any jobs scheduled for the past when the worker wasn't running are
     * also queued up.
     *
     * @param DateTime|int $at Instance of DateTime or UNIX timestamp.
     *      Defaults to now.
     * @return int UNIX timestamp, or false if nothing to run.
     */
    public static function nextDelayedTimestamp($at = null) : ?int
    {
        if ($at === null) {
            $at = time();
        } else {
            $at = self::getTimestamp($at);
        }

        $items = Resque::redis()->zrangebyscore(self::QUEUE_NAME, '-inf', $at,
            ['limit', 0, 1]);

        if (!empty($items)) {
            return (int)$items[0];
        }

        return null;
    }

    /**
     * Pop a job off the delayed queue for a given timestamp.
     *
     * @param DateTime|int $timestamp Instance of DateTime or UNIX timestamp.
     * @return mixed[] Matching job at timestamp.
     */
    public static function nextItemForTimestamp($timestamp) : ?array
    {
        $timestamp = self::getTimestamp($timestamp);
        $key = self::QUEUE_NAME . ':' . $timestamp;
        $json = Resque::redis()->lpop($key);

        self::cleanupTimestamp($key, $timestamp);

        if (!empty($json)) {
            $item = json_decode($json, true, Resque::JSON_DECODE_DEPTH,
                Resque::JSON_DECODE_OPTIONS);

            if (!empty($item)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Ensure that supplied job class/queue is valid.
     *
     * @param string $class Name of job class.
     * @param string $queue Name of queue.
     * @return bool True if the job and class are valid.
     * @throws ResqueException
     */
    private static function validateJob(string $class, string $queue) : bool
    {
        if (empty($class)) {
            throw new ResqueException('Jobs must be given a class.');
        } elseif (empty($queue)) {
            throw new ResqueException('Jobs must be put in a queue.');
        }

        return true;
    }
}
