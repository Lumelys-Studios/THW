<?php

namespace SilverSky;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\utils\TextFormat as TF;
use pocketmine\utils\Config;
use SilverSky\CallbackTask;
use SilverSky\gameStartTask;

/**
 * ============================================
 *  Touhou_Wars（职业战争 / KitWars）插件主类
 * ============================================
 *  - extends PluginBase      : 服务器核心插件基类，onEnable() 在插件加载时由核心自动调用
 *  - implements Listener     : 实现事件监听接口，配合 registerEvents() 接收服务器各类事件
 *  - 用 use 引入 6 个 trait，把不同功能拆到独立文件（便于区分各模块职责）：
 *      PlayerListener   玩家生命周期事件（加入/死亡/退出/禁丢物品）
 *      CombatListener   战斗伤害事件 + 职业战斗技能触发
 *      InteractListener 右键交互触发技能 + 选择队伍
 *      CommandHandler   /hub /kits 指令处理
 *      KitManager       职业装备/道具发放
 *      GameManager      游戏流程（回血/时停/开局/胜负结算/强制分队）
 */
class Main extends PluginBase implements Listener{
    use PlayerListener, CombatListener, InteractListener, CommandHandler, KitManager, GameManager;

	const motion = 3;                                   // 火球/大火球的飞行速度倍率
	public $prefix = TF::DARK_GRAY . "[" . TF::LIGHT_PURPLE . "Touhou_Wars" . TF::DARK_GRAY . "]"; // 插件消息前缀（带颜色代码，粉色）
	public $red = array();       // 红队成员名单（键值均为玩家名，用于判断队伍归属）
	public $yellow = array();    // 黄队成员名单（当前版本未启用）
	public $green = array();     // 绿队成员名单（当前版本未启用）
	public $blue = array();      // 蓝队成员名单
        public $stopTime;   // "时停"剩余 tick 数（血之怀表触发后倒计时）
        public $stopPos;    // "时停"中心坐标，半径 6 格内的实体被定身

	/**
	 * 插件加载入口 —— 服务器核心启动插件时自动调用
	 *  核心 API 说明：
	 *   - getServer()                   : 获取服务器主实例（pocketmine\Server）
	 *   - getPluginManager()            : 插件管理器，registerEvents() 把本类注册为全局事件监听者
	 *   - loadLevel("kitwars")          : 加载/预加载游戏地图（对应 worlds/kitwars）
	 *   - getDataFolder()               : 插件数据目录（plugins/Touhou_Wars/）
	 *   - new Config(..., Config::YAML) : 核心提供的 YAML 配置文件读写工具
	 *   - getScheduler()->scheduleRepeatingTask(): 注册"每 N tick 重复执行"的任务
	 *       （1 tick = 1/20 秒；80 tick=4秒, 20 tick=1秒, 1 tick=每帧）
	 *       CallbackTask([$this,"db"])   → 每 80 tick 给地图内玩家回血
	 *       gameStartTask($this)         → 每 20 tick 检测开局/胜负
	 *       CallbackTask([$this,"timeStop"]) → 每 1 tick 处理"时停"定身效果
	 */
	public function onEnable(){
            $this->getLogger()->info(TF::YELLOW . "Author: SilverSky . The plugin is loading!");
            $this->getServer()->getPluginManager()->registerEvents($this,$this); // 注册本插件的事件监听
            $this->getServer()->loadLevel("kitwars"); // 加载职业战争地图
            @mkdir($this->getDataFolder(), 0777); // 确保数据目录存在（@ 忽略已存在报错）
            @mkdir($this->getDataFolder() . "Teams/", 0777); // 玩家队伍数据目录
            $options = new Config($this->getDataFolder() . "config.yml", Config::YAML); // 读取/创建游戏状态配置
            $options->set("Start", 0);    // Start=0 表示游戏未开始，=1 表示进行中
            $options->set("Starting", 0); // Starting=1 表示开局倒计时中
            $options->set("Count", 8);    // 每队人数上限
            $options->set("timeStop", 0); // timeStop=1 表示正处于"时停"状态
            $options->save();
        $this->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask([$this,"db"]), 80); // 每4秒回血任务
        $this->getServer()->getScheduler()->scheduleRepeatingTask(new gameStartTask($this), 20)->getTaskId(); // 每秒开局检测任务
        $this->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask([$this,"timeStop"]), 1); // 每帧时停处理
		$team = glob($this->getDataFolder() . "Teams/*"); // 清空上一次游戏遗留的玩家队伍文件
		foreach($team as $file){
			if(is_file($file)){
				@unlink($file);
			}
		}
	}

	/**
	 * 插件卸载入口 —— 服务器关闭/重载插件时调用
	 *  将游戏状态重置为"未开始"，并释放内存中的冷却表与时停状态，
	 *  避免下次加载时残留旧数据。
	 */
	public function onDisable(){
		$this->getLogger()->info("The plugin is unload!");
		$options = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $options->set("Start", 0);      // 复位：游戏未开始
        $options->set("Starting", 0);   // 复位：不在开局倒计时
        $options->set("timeStop", 0);   // 复位：解除时停
		$options->save();
        unset($this->lengque); // 清空所有技能冷却表
        unset($this->stopTime); // 清空时停计时
	}

}
