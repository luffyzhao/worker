<?php


namespace LWorker\Interfaces;


use Throwable;

interface WithException
{
    public function exception(Throwable $e);
}