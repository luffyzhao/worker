<?php


namespace LWorker\Interfaces;


interface WorkerInterface
{
    /**
     * 执行
     */
    public function handle():void;
}