<?php

declare(strict_types=1);

namespace Jine\EventBus;

use Jine\EventBus\Enum\ResultStatus;
use Jine\EventBus\Dto\Result;
use Jine\EventBus\Dto\Task;
use Closure;

use function array_flip;
use function array_intersect_key;
use function count;
use function key;
use function call_user_func;

abstract class AbstractDispatcher
{
    protected TaskFactory $taskFactory;
    protected TaskStorage $taskStorage;
    protected Loop $loop;
    protected SubscribeStorage $subscribeStorage;
    protected ActionStorage $actionStorage;
    protected ResultStorage $resultStorage;
    protected ?Closure $externalCallback;
    protected array $heldTasks = [];
    protected string $startAction;

    public function __construct(
        TaskFactory $taskFactory,
        TaskStorage $taskStorage,
        Loop $loop,
        SubscribeStorage $subscribeManager,
        ActionStorage $actionStorage,
        ResultStorage $resultStorage
    ) {
        $this->loop = $loop;
        $this->taskFactory = $taskFactory;
        $this->taskStorage = $taskStorage;
        $this->subscribeStorage = $subscribeManager;
        $this->actionStorage = $actionStorage;
        $this->resultStorage = $resultStorage;
    }

    protected function runLoop(string $startAction, ?Closure $externalCallback): void
    {
        $this->prepareToRun($startAction, $externalCallback);

        $this->loop->run(
            function ($result) {
                if ($result === null) {
                    $this->loop->next();
                } else {
                    $this->dispatchResultEvent($result);
                }
            }
        );
    }

    protected function prepareToRun(string $startAction, ?Closure $externalCallback): void
    {
        $action = $this->actionStorage->get($startAction);

        $task = $this->taskFactory->create($action);

        $this->externalCallback = $externalCallback;

        $this->startAction = $startAction;

        $this->dispatchRequired($task);

        $this->loop->addTask($task);
    }

    protected function dispatchSubscribersTasks(Result $result, Task $resultTask): void
    {
        $subscribers = $this->getSubscribers($result, $resultTask);

        if (!empty($subscribers)) {
            foreach ($subscribers as $subscribe) {

                $action = $this->actionStorage->get($subscribe->actionFullName);

                $task = $this->taskFactory->create($action);

                $this->dispatchRequired($task);

                $this->dispatchTask($task);
            }
        }
    }

    protected function getSubscribers(Result $result, Task $resultTask): array
    {
        $subject = $resultTask->serviceId . '.' . $resultTask->action . '.' . $result->status;

        return $this->subscribeStorage->getSubscribers($subject);
    }

    protected function dispatchResultEvent(Result $result): void
    {
        if ($this->loop->isEmpty() && $this->externalCallback !== null) {
            $busResult = $this->resultStorage->getResult($this->startAction);
            call_user_func($this->externalCallback, $busResult);
            return;
        }

        $resultTask = $this->loop->getCurrentTask();

        if ($result->status === ResultStatus::SUCCESS) {
            $this->taskStorage->save($resultTask);
        }

        $this->dispatchHeld();

        $this->dispatchSubscribersTasks($result, $resultTask);

        $this->loop->next();
    }

    protected function dispatchRequired(Task $task): void
    {
        if (empty($task->required)) {
            return;
        }

        foreach ($task->required as $subject) {

            $serviceAction = $this->actionStorage->get($subject);

            $this->prepareRequiredTasks($serviceAction);
        }
    }

    private function prepareRequiredTasks(Action $action): void
    {
        if ($this->taskStorage->isExistsByActionFullName($action->serviceId . '.' . $action->name)) {
            return;
        }

        $task = $this->taskFactory->create($action);

        $this->dispatchTask($task);

        $this->dispatchRequired($task);
    }

    protected function dispatchTask(Task $task): void
    {
        if ($this->isDispatchable($task)) {
            if ($this->isSatisfied($task)) {
                $this->loop->addTask($task);
            } else {
                $this->heldTasks[$task->serviceId . '.' . $task->action] = $task;
            }
        }
    }

    private function dispatchHeld()
    {
        foreach($this->heldTasks as $task) {
            if ($this->isSatisfied($task)) {
                $this->loop->addTask($task);
                unset($this->heldTasks[key($this->heldTasks)]);
            }
        }
    }

    private function isSatisfied(Task $task): bool
    {
        $completeTasks = $this->taskStorage->getAll();

        $requiredTasksData = array_intersect_key($completeTasks, array_flip($task->required));

        return count($requiredTasksData) === count($task->required);
    }

    protected function isDispatchable(Task $task): bool
    {
        if ($task->repeat) {
            return true;
        }

        if ($this->taskStorage->isExists($task)) {
            return false;
        }

        return true;
    }
}
