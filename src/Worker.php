<?php
namespace LWorker;

use Exception;
use LWorker\Interfaces\WorkerInterface;
use LWorker\Interfaces\WithException;
use LWorker\Interfaces\WithShouldRestart;
use LWorker\Interfaces\WithTimout;
use Throwable;

class Worker
{

    /**
     * 是否维护模式
     *
     * @var callable
     */
    protected $isDownForMaintenance;
    /**
     * 进程是否应该退出
     *
     * @var bool
     */
    public $shouldQuit = false;

    /**
     * 进程是否已暂停
     *
     * @var bool
     */
    public $paused = false;

    /**
     * 进程配置
     * @var WorkerOptions
     */
    private $options;

    /**
     * Worker constructor.
     * @param callable $isDownForMaintenance
     */
    public function __construct(callable $isDownForMaintenance){
        $this->isDownForMaintenance = $isDownForMaintenance;
    }

    /**
     * 开始运行
     * @param WorkerInterface $worker
     * @param WorkerOptions $options
     */
    public function daemon(WorkerInterface $worker,
                           WorkerOptions  $options){
        if ($this->supportsAsyncSignals()) {
            $this->listenForSignals();
        }

        while (true) {
            if (! $this->daemonShouldRun($options)){
                continue;
            }


            if($this->supportsAsyncSignals()){
                // 注册超时退出
                $this->registerTimeoutHandler($worker, $options);
            }

            // 业务代码
            $this->runWorker($worker);

            if ($this->supportsAsyncSignals()) {
                // 移除注册超时退出
                $this->resetTimeoutHandler();
            }
            // 必须停止
            $this->stopIfNecessary($worker, $options);

            // 需要睡眠
            $this->workerShouldSleep($options);
        }
    }

    /**
     * 是否支持异步信号
     * @return bool
     */
    private function supportsAsyncSignals():bool
    {
        return extension_loaded('pcntl');
    }

    /**
     * 监听进程信号
     */
    private function listenForSignals()
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function () {
            $this->shouldQuit = true;
        });

        pcntl_signal(SIGUSR2, function () {
            $this->paused = true;
        });

        pcntl_signal(SIGCONT, function () {
            $this->paused = false;
        });
    }

    /**
     * 需要运行
     * @param WorkerOptions $options
     * @return bool
     */
    private function daemonShouldRun(WorkerOptions  $options):bool
    {
        return !(($this->isDownForMaintenance)() ||  $this->paused);
    }

    /**
     * 注册单个任务超时退出
     * @param WorkerInterface $worker
     * @param WorkerOptions $options
     */
    private function registerTimeoutHandler(WorkerInterface $worker, WorkerOptions $options)
    {
        //我们将为报警信号注册一个信号处理程序，这样我们就可以终止它
        //如果由于冻结而运行时间过长，则处理。这使用异步
        //最新版本的PHP支持的信号，以方便地完成它。
        pcntl_signal(SIGALRM, function () use ($options) {
            $this->kill(1);
        });

        pcntl_alarm(
            max($this->timeoutForJob($worker, $options), 0)
        );
    }

    /**
     * @param WorkerInterface $worker
     * @param WorkerOptions $options
     * @return int
     */
    private function timeoutForJob(WorkerInterface $worker, WorkerOptions $options):int
    {
        return $worker instanceof WithTimout ? $worker->timout() : $options->timeout;
    }

    /**
     * 退出进程
     *
     * @param  int  $status
     * @return void
     */
    public function kill(int $status = 0)
    {
        if (extension_loaded('posix')) {
            posix_kill(getmypid(), SIGKILL);
        }

        exit($status);
    }

    /**
     * 移除单个任务超时退出
     */
    private function resetTimeoutHandler()
    {
        pcntl_alarm(0);
    }

    /**
     * 必须要停止的情况
     * @param WorkerInterface $worker
     * @param WorkerOptions $options
     */
    private function stopIfNecessary(WorkerInterface $worker, WorkerOptions $options)
    {
        if ($this->shouldQuit) {
            $this->stop();
        } elseif ($this->memoryExceeded($options->memory)) {
            $this->stop(12);
        } elseif ($this->workerShouldRestart($worker)) {
            $this->stop();
        }
    }

    /**
     * Stop listening and bail out of the script.
     *
     * @param  int  $status
     * @return void
     */
    public function stop(int $status = 0)
    {
        exit($status);
    }

    /**
     * @param int $memoryLimit
     * @return bool
     */
    private function memoryExceeded(int $memoryLimit) : bool
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $memoryLimit;
    }

    /**
     * 进程必须重启
     * @param WorkerInterface $worker
     * @return bool
     */
    private function workerShouldRestart(WorkerInterface $worker) : bool
    {
        return $worker instanceof WithShouldRestart && $worker->shouldRestart();
    }

    /**
     * @param WorkerInterface $worker
     */
    private function runWorker(WorkerInterface $worker)
    {
        try{
            $worker->handle();
        }catch (Exception | Throwable $exception){
            if($worker instanceof WithException){
                $worker->exception($exception);
            }
        }
    }

    /**
     * 睡眠
     * @param WorkerOptions $options
     */
    private function workerShouldSleep(WorkerOptions $options)
    {
        if($options->sleep > 0){
            sleep($options->sleep);
        }
    }
}