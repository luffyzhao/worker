<?php


namespace LWorker\Interfaces;


interface WithShouldRestart
{
    public function shouldRestart():bool;
}