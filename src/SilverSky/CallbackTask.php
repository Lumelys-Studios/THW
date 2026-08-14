<?php
namespace SilverSky;

use pocketmine\scheduler\Task;

/**
 * ============ CallbackTask：通用回调任务 ============
 * extends Task（服务器核心的定时任务基类）。
 * 本插件用它在"指定 tick 后"或"每 N tick"执行任意方法：
 *   new CallbackTask([对象, "方法名"], [参数...])
 * 例：new CallbackTask([$this, "remove"], ["fireball", $name])  → 到点调用 $this->remove("fireball", $name)
 * 例：new CallbackTask([$this, "db"]) → 每 80 tick 调用 $this->db()
 */
class CallbackTask extends Task{

    protected $callable; // 要调用的回调（数组形式 [对象, 方法] 或可调用闭包）
    protected $args;     // 传给回调的参数

    public function __construct(callable $callable, array $args = []){
        $this->callable = $callable;
        $this->args = $args;
        $this->args[] = $this; // 末尾附加任务自身（供回调使用）
    }

    public function getCallable(){
        return $this->callable;
    }

    public function onRun($currentTicks){
        \call_user_func_array($this->callable, $this->args); // 核心每 tick 调用：执行回调并传入参数
    }
}