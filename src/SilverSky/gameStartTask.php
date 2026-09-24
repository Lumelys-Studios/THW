<?php

namespace SilverSky;

use pocketmine\Server;
use pocketmine\Player;
use pocketmine\scheduler\PluginTask;
use pocketmine\utils\Config;
use SilverSky\Main;
use pocketmine\utils\TextFormat as TF;

/**
 * ============ gameStartTask：游戏开局/胜负检测定时任务 ============
 * extends PluginTask：服务器核心的循环任务类，onRun() 每 tick 被核心调用一次
 * （Main::onEnable 里注册为每 20 tick = 1 秒执行一次）。
 * 职责分两阶段：
 *   1) 未开始时：玩家数≥2 则进入 60 秒开局倒计时，归零后强制分队+开局（gameStart）
 *   2 已开始时：每帧刷新存活队伍数（$team）与名字标签，队伍只剩 1 个 → 胜利结算
 *     （gameWinEnd）；开局 1800 秒倒计时归零 → 平局结算（gameEnd）
 */
class gameStartTask extends PluginTask{


	public function __construct(Main $plugin){
		parent::__construct($plugin); // 核心要求：把插件实例传给父类，任务才能随插件卸载而取消
		$this->plugin = $plugin; // 保存主插件引用，便于调用其公开方法
	}

	public function onRun($tick){

        static $startseconds = 1800; // 游戏进行总时长上限（秒）
        static $seconds = 60;        // 开局倒计时（秒）

        // 兜底：kitwars 地图被意外卸载（管理操作/其他插件）时自动重载，
        // 避免 getLevelByName 返回 null 导致本任务每帧报错、倒计时永远无法开始
        $level = $this->plugin->getServer()->getLevelByName("kitwars");
        if($level === null){
            $this->plugin->getServer()->loadLevel("kitwars");
            $level = $this->plugin->getServer()->getLevelByName("kitwars");
        }
        if($level === null){
            return; // 地图确实无法加载：跳过本帧，等下次再试，不中断任务
        }

        $options = new Config($this->plugin->getDataFolder() . "config.yml", Config::YAML);
        // 兜底：游戏未开始时，确保开局倒计时与总时长处于初始值。
        // 这样即使上一局结算异常漏掉了对 static 变量的重置，也能正常开始下一局。
        if($options->get("Start") == 0){
            if($seconds <= 0){
                $seconds = 60;
            }
            if($startseconds < 1800){
                $startseconds = 1800;
            }
        }
		$i = 0; // 统计游戏房间内的玩家数（按"在 kitwars 地图"判断，不依赖 gamemode，避免 gamemode 残留导致倒计时统计不到）
		foreach($level->getPlayers() as $player){
			$i++;
		}
		// ---------- 阶段1：开局倒计时 ----------
		// 人数 ≥2 时倒计时持续进行（玩家中途退出不影响，只要房间仍 ≥2 人）；
		// 人数 <2 时倒计时重置回 60 秒，等玩家补足 2 人后重新开始
		if($options->get("Start") == 0 and $seconds > 0){
            if($i >= 2){
                $options->set("Starting", 1); // 标记"开局中"
                $options->save();
			    $seconds--; // 每秒递减
                foreach($this->plugin->getServer()->getLevelByName("kitwars")->getPlayers() as $player){
                    $player->sendPopup($this->plugin->prefix . TF::GREEN . "游戏还有 $seconds 秒开始");
                }
                if($seconds == 0){ // 倒计时结束，正式开局
                    $options->set("Start", 1); // 标记游戏已开始
                    $options->set("Starting", 0);
                    $options->save();
                    try{
                        $this->plugin->gameStart(); // 只调用一次：内部 balanceTeams 均衡分队 + 发放装备 + 传送出生点
                    }catch(\Exception $e){
                        $this->plugin->getLogger()->warning("[Touhou_Wars] 开局异常: " . $e->getMessage());
                    }
                }
            }else{
                // 房间人数 <2：倒计时重置回 60 秒，等玩家补足 2 人后重新开始
                $seconds = 60;
                $options->set("Starting", 0);
                $options->save();
            }
		}
        // ---------- 阶段2：游戏进行中，检测存活队伍与超时 ----------
        if($options->get("Start") == 1){
            // 兜底：游戏进行中但 kitwars 房间已无玩家（全体退出/断线，或上一局结算遗漏）
            // → 自动结束本局并复位游戏状态，保证随时能开始下一局（否则 Start 卡 1 无法重开）
            $roomHasPlayer = false;
            foreach($this->plugin->getServer()->getLevelByName("kitwars")->getPlayers() as $player){
                $roomHasPlayer = true; // 在 kitwars 即视为有玩家（不依赖 gamemode）
                break;
            }
            if(!$roomHasPlayer){
                $startseconds = 1800;
                $seconds = 60;
                $options->set("Start", 0);
                $options->set("Starting", 0);
                $options->set("timeStop", 0);
                $options->save();
                unset($this->plugin->stopTime);
                unset($this->plugin->stopPos);
                unset($this->plugin->lengque);
                $this->plugin->red = array();
                $this->plugin->yellow = array();
                $this->plugin->green = array();
                $this->plugin->blue = array();
                return;
            }
            $array = array();
            $team = 0; // 存活队伍种类数（0=暂时无存活玩家）
        if($startseconds > 0){ // 游戏进行倒计时
            $startseconds--;
            $i = 0;
            $None = "                                                             ";
            foreach($this->plugin->getServer()->getLevelByName("kitwars")->getPlayers() as $player){
               // 胜负只计入"在 kitwars、存活、且已有队伍"的玩家（有队伍即视为参与本局，不依赖 gamemode，
               // 避免 1v1 等其他插件改动 gamemode 后，存活玩家被漏计导致只剩一队也不结束）
               if($player->getLevel()->getFolderName() == "kitwars"){
                	$i++;
                	$name = $player->getName();
                	$config = new Config($this->plugin->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
                	// 死亡玩家不计入存活队伍：即使 onDeath 因异常漏清 team 残留，
                	// 死亡观战的玩家也不能继续"占着队伍"卡住胜负判定（否则游戏结束不了、
                	// Start 卡 1，下一局倒计时永远无法开始）
                	if(!$player->isAlive()){
                	    continue;
                	}
                	// 只统计有效队伍（排除死亡/未分队玩家的 null），
                	// 否则死亡玩家 team=null 会让队伍种类永远 >1，胜利检测无法触发
                	$teamVal = $config->get("team");
                	if($teamVal != "null" and $teamVal != null and $teamVal != ""){
                	    $array[] = $teamVal;
                	}
                	$health = $player->getHealth();
                	$maxHealth = $player->getMaxHealth();
                	$kit = $config->get("kit");
                    if($player->getLevel()->getFolderName() == "kitwars"){
                		$job = $this->plugin->getJobName($kit);

                		if($config->get("team") == "red"){
                        	$player->setNameTag(TF::DARK_GRAY . "[" . TF::RED. $job . TF::WHITE . " $health/$maxHealth" . TF::DARK_GRAY . "]" . TF::RED . $name);
                    	}elseif($config->get("team") == "blue"){
                        	$player->setNameTag(TF::DARK_GRAY . "[" . TF::BLUE. $job . TF::WHITE . " $health/$maxHealth" . TF::DARK_GRAY . "]" . TF::BLUE . $name);
                    	}
            		}
                }
            }
                // 统计存活队伍种类数与每个队伍的存活玩家数
                $team = count(array_unique($array));
                $teamCounts = array_count_values($array);
                $surviveMsg = "";
                if(isset($teamCounts["red"])){
                    $surviveMsg .= TF::RED . "红队" . $teamCounts["red"] . "人 ";
                }
                if(isset($teamCounts["yellow"])){
                    $surviveMsg .= TF::YELLOW . "黄队" . $teamCounts["yellow"] . "人 ";
                }
                if(isset($teamCounts["green"])){
                    $surviveMsg .= TF::GREEN . "绿队" . $teamCounts["green"] . "人 ";
                }
                if(isset($teamCounts["blue"])){
                    $surviveMsg .= TF::BLUE . "蓝队" . $teamCounts["blue"] . "人 ";
                }
                if($surviveMsg == ""){
                    $surviveMsg = TF::GRAY . "无";
                }
            foreach($this->plugin->getServer()->getLevelByName("kitwars")->getPlayers() as $player){
                   $player->sendPopup($None . $this->plugin->prefix . "\n" . $None . TF::GREEN . "存活: " . $surviveMsg);
            	}
            if($team == 1){
                $startseconds = 1800;
                $seconds = 60;
                $winnerTeam = strval($array[0]); // 剩余的唯一队伍即为胜者
                $this->plugin->gameWinEnd($winnerTeam); // 传入胜者，避免二次检测的竞态
            }elseif($team == 0){
                // 兜底：房间有玩家但无任何有效队伍（全员未分队 / 全员阵亡后 team 均为 null / 存活方已离房）
                // → 游戏已无法决出胜负，直接结束本局，避免 Start 卡 1 导致下一局无法重开
                $this->plugin->getLogger()->warning("[Touhou_Wars] 开局后无有效队伍，自动结束本局 (房间人数 i=$i)");
                $startseconds = 1800;
                $seconds = 60;
                $this->plugin->gameEnd();
            }
            }
        	if($startseconds == 0){
                $startseconds = 1800;
                $seconds = 60;
                $this->plugin->gameEnd();
            }
        }
	}
}