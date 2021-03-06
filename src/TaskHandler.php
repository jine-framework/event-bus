<?php 

declare(strict_types=1);

namespace Jine\EventBus;

use Jine\EventBus\Dto\Result;
use Jine\EventBus\Dto\Task;
use Jine\EventBus\Contract\HandlerInterface;
use Closure;
use Throwable;

class TaskHandler
{
    private Rollback $rollback;
    private ResultStorage $resultStorage;
    private TaskStorage $taskStorage;
    private Container $container;
    private array $containers = [];
    
    public function __construct(
        Rollback $rollback,
        ResultStorage $resultStorage,
        TaskStorage $taskStorage,
        Container $container
    ) {
        $this->rollback = $rollback;
        $this->resultStorage = $resultStorage;
        $this->taskStorage = $taskStorage;
        $this->container = $container;
    }

    public function handle(Task $task, Closure $callback): void
    {
        try {
            $handler = $this->prepareService($task);
            $result = $this->run($handler, $task);
            $callback($result);
        } catch(Throwable $exception) {
            $this->taskStorage->save($task);
            $this->rollback();
            throw $exception;
        }
    }

    private function prepareService(Task $task): HandlerInterface
    {
        $this->containers[$task->serviceId] = clone $this->container;

        $results = $this->resultStorage->getAllByRequired($task->required);

        foreach ($results as $result) {
            if ($result->data !== null) {
                $this->containers[$task->serviceId]->set($result->data);
            }
        }

        if (!empty($task->classMap)) {
            $this->containers[$task->serviceId]->setClassMap($task->classMap);
        }

        return $this->containers[$task->serviceId]->instance($task->handler);
    }

    private function run(HandlerInterface $handler, Task $task): ?Result
    {
        $result = null;
        $result ??= $handler->run();

        if ($result !== null) {
            $this->resultStorage->save($task->serviceId . '.' . $task->action, $result);
        }

        return $result;
    }

    public function rollback(): void
    {
        $this->rollback->run($this->containers);
    }
}
