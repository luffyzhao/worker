<?php


namespace LWorker;


class WorkerOptions
{
    /**
     * 支持单个 \Closure 处理的最大时间
     *
     * @var int
     */
    public $timeout = 10;

    /**
     * 进程结束后睡多久
     *
     * @var int
     */
    public $sleep = 0;
    /**
     * 最大可占用多少内存
     *
     * @var int
     */
    public $memory = 128;

}