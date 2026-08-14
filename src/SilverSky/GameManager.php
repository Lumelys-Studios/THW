<?php

namespace SilverSky;

use SilverSky\CallbackTask;
use SilverSky\gameStartTask;
use SilverSky\Entity\FireBall;
use SilverSky\Entity\BigFireBall;
use SilverSky\Entity\NoBuffPotion;
use pocketmine\scheduler\PluginTask;
use pocketmine\plugin\PluginBase;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Lightning;
use pocketmine\entity\ThrownPotion;
use pocketmine\entity\Effect;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\utils\TextFormat as TF;
use pocketmine\utils\Config;
use pocketmine\utils\Random;
use pocketmine\item\Item;
use pocketmine\item\Potion;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\level\sound\AnvilFallSound;
use pocketmine\level\particle\EnchantmentTableParticle;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\defaults\ParticleCommand;

/**
 * ============ GameManager：游戏流程控制 ============
 * 管理职业战争的核心游戏逻辑：每帧时停效果、自动回血、开局传送、
 * 胜负结算、超时平局、强制分队、冷却标记清理。
 * 大部分方法由 onEnable 中注册的定时任务（CallbackTask / gameStartTask）驱动。
 */
trait GameManager
{
    /**
     * "时停"处理（每 1 tick 执行一次）
     * 由 Main::onEnable 的 CallbackTask([$this,"timeStop"]) 驱动。
     * 当 config 的 timeStop=1 且存在 stopTime 时：每 tick 让 stopTime 倒计时，
     * 并把 stopPos 半径 4 格内、非"从者/时停者"的实体持续拉回原位并施加缓速
     * （效果ID 15=缓慢），实现"时间停止"的效果。倒计时结束则解除时停并复位所有玩家的 timeStoper 标记。
     */
    public function timeStop(){
        $options = new Config($this->getDataFolder() . "config.yml", Config::YAML);
    				if($options->get("timeStop") == 1 and isset($this->stopTime)){ // 处于时停状态
                        if($this->stopTime > 0){
                            $this->stopTime--;
                        	foreach($this->getServer()->getLevelByName("kitwars")->getEntities() as $entity){
                                if(isset($this->stopPos) and $entity->distance($this->stopPos) <= 4){
                                	if($entity instanceof Player){
                                        if($entity->getGamemode() == 2){
                                    		$en = $entity->getName();
                                    		$econfig = new Config($this->getDataFolder() . "Teams/" . "$en.yml", Config::YAML);
                                    		if($econfig->get("kit") != "timer"){
                                        		$pos = new Vector3($entity->x, $entity->y, $entity->z);
                                				$entity->teleport($pos);
                                            	$entity->addEffect(Effect::getEffect(15)->setDuration(20)->setAmplifier(0)->setVisible(true));
                                    		}elseif($econfig->get("timeStoper") != 1){
                                        		$pos = new Vector3($entity->x, $entity->y, $entity->z);
                                				$entity->teleport($pos);
                                            	$entity->addEffect(Effect::getEffect(15)->setDuration(20)->setAmplifier(0)->setVisible(true));
                                            	}
                                    		}
                                		}else{
                                    		$pos = new Vector3($entity->x, $entity->y, $entity->z);
                                        $entity->addEffect(Effect::getEffect(15)->setDuration(20)->setAmplifier(0)->setVisible(true));
                                			$entity->teleport($pos);
                                		}
	                                }
    	                    	}
        	            	}else{
            	                foreach($this->getServer()->getOnlinePlayers() as $player){
                	                $name = $player->getName();
                    	            $config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
                        	    	$config->set("timeStoper", 0);
                            		$config->save();
                            	}
                            	$options->set("timeStop", 0);
                            	$options->save();
                           		unset($this->stopTime);
                           		unset($this->stopPos);
                        	}
                    	}
                	}

	/**
	 * 自动回血（每 80 tick=4秒 执行一次）
	 * 由 Main::onEnable 的 CallbackTask([$this,"db"]) 驱动。
	 * 游戏地图内存活的玩家每秒回 1 点血；时停期间"时停者"也能正常回血。
	 */
	public function db(){
        foreach($this->getServer()->getLevelByName("kitwars")->getPlayers() as $player){ // 遍历游戏地图内玩家
            if($player->isAlive()){
                $name = $player->getName();
                $options = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        		$config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
                if($options->get("timeStop") == 0){
                	$player->setHealth($player->getHealth() + 1);
                }elseif($config->get("timeStoper") == 1){
                    $player->setHealth($player->getHealth() + 1);
                }
            }
        }
    }

	/**
	 * 游戏开局（由 gameStartTask 在倒计时结束后调用）
	 * 核心 API：Vector3 三维坐标，teleport() 传送玩家到出生点。
	 * 流程：清空背包 → 发放职业装备（kitGive）→ 更新头顶名字标签 →
	 *       按队伍传送到对应出生点（红队/蓝队，黄绿坐标已停用）。
	 */
	public function gameStart(){
		$level = $this->getServer()->getLevelByName("kitwars");
		$this->balanceTeams(); // 开局先把两队人数尽可能均分，再发放装备/传送
		$red = new Vector3(227, 43, -103); // 红队出生点
		$yellow = new Vector3(89, 124, 183);
        $green = new Vector3(181, 95, 63);
		$blue = new Vector3(36, 47, 71);
			foreach($level->getPlayers() as $player){
                $player->getInventory()->clearAll();
                $this->kitGive($player);
				$name = $player->getName();
				$config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
				if($player->getGamemode() == 2){
                	$health = $player->getHealth();
                	$maxHealth = $player->getMaxHealth();
                	$name = $player->getName();
                	$config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
                	$kit = $config->get("kit");
                    if($player->getLevel()->getFolderName() == "kitwars"){
                    	if($kit == "mage"){
                        	$job = "魔法使";
                    	}elseif($kit == "miko"){
                        	$job = "巫女";
                    	}elseif($kit == "berserker"){
                        	$job = "狂战士";
                    	}elseif($kit == "vampire"){
                        	$job = "吸血鬼";
                    	}elseif($kit == "archer"){
                        	$job = "弓兵";
                    	}elseif($kit == "saber"){
                        	$job = "剑士";
                    	}elseif($kit == "timer"){
                            $job = "从者";
                        }
                		if($config->get("team") == "red"){
                        	$player->setNameTag(TF::DARK_GRAY . "[" . TF::RED. $job . TF::WHITE . " $health/$maxHealth" . TF::DARK_GRAY . "]" . TF::RED . $name);
                    	}elseif($config->get("team") == "blue"){
                        	$player->setNameTag(TF::DARK_GRAY . "[" . TF::BLUE. $job . TF::WHITE . " $health/$maxHealth" . TF::DARK_GRAY . "]" . TF::BLUE . $name);
                    	}
            		}
					if($config->get("team") == "red"){
						$player->teleport($red);
					}/*elseif($config->get("team") == "yellow"){
						$player->teleport($yellow);
					}elseif($config->get("team") == "green"){
						$player->teleport($green);
					}*/elseif($config->get("team") == "blue"){
						$player->teleport($blue);
                    }
				}
			}
		}

    /**
     * 强制开始游戏（/thw forcestart 调用）
     * 准备阶段房间内玩家 ≥2 时：跳过倒计时，直接进入开始状态，
     * 对房间内玩家强制分队（forceTeam）+ 发放装备并传送出生点（gameStart）。
     * 把 config 的 Start 置 1 后，gameStartTask 检测到 Start=1 会跳过倒计时，避免重复开局。
     */
    public function forceStartGame($sender){
        $options = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        if($options->get("Start") == 1){ // 游戏已在运行中
            $sender->sendMessage($this->prefix . TF::RED . "游戏已在运行中！");
            return;
        }
        // 统计房间内玩家数（观察者模式 gamemode=2）
        $count = 0;
        $players = array();
        foreach($this->getServer()->getLevelByName("kitwars")->getPlayers() as $player){
            if($player->getGamemode() == 2){
                $count++;
                $players[] = $player;
            }
        }
        if($count < 2){
            $sender->sendMessage($this->prefix . TF::RED . "人数不足，需要至少 2 名玩家（当前 $count 人）！");
            return;
        }
        // 直接进入开始状态
        $options->set("Start", 1);
        $options->set("Starting", 0);
        $options->save();
        foreach($players as $player){
            $this->gameStart($player); // 发放装备+传送出生点（gameStart 内部 balanceTeams 统一均衡分队）
        }
        foreach($this->getServer()->getLevelByName("kitwars")->getPlayers() as $player){
            $player->sendMessage($this->prefix . TF::GREEN . "管理员已强制开始游戏！");
        }
        $sender->sendMessage($this->prefix . TF::GREEN . "已强制开始游戏！");
    }

    /**
     * 清除技能冷却标记（由各技能里的 CallbackTask 定时调用）
     * 核心 API：unset() 删除数组元素。$value=技能名, $name=玩家名。
     * 效果：删除 $this->lengque["技能名"][玩家名]，使该技能冷却结束可再次释放。
     */
    public function remove($value, $name){
        if(isset($this->lengque[$value][$name])){
            unset($this->lengque[$value][$name]); // 删除冷却标记
        	}
    	}

    /**
     * 胜利结算（由 gameStartTask 检测到只剩一个存活队伍时调用）
     * 核心 API：array_unique() 统计地图内剩余队伍种类。
     * 流程：统计存活队伍 → 若只剩一种 → 宣布胜者队伍 → 所有玩家传回大厅、
     *       清背包/效果、复位职业队伍、重置游戏状态（Start=0）、清空队伍名单与冷却表。
     */
    /**
     * 重置整局游戏状态，确保每局结束后都能开始下一局
     * - 配置文件：Start / Starting / timeStop 复位
     * - 内存状态：时停、冷却表、队伍名单清空
     * - 所有在线玩家：传送回大厅、清背包、回血、恢复显示名与称号、清队伍/职业配置
     * 单个玩家的异常不会中断整体重置（try/catch）。
     */
    public function resetGame(){
        // 重置配置文件状态
        $options = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $options->set("Start", 0);
        $options->set("Starting", 0);
        $options->set("timeStop", 0);
        $options->save();
        // 清空内存中的时停 / 冷却 / 队伍状态
        unset($this->stopTime);
        unset($this->stopPos);
        unset($this->lengque);
        $this->red = array();
        $this->yellow = array();
        $this->green = array();
        $this->blue = array();
        // 重置所有在线玩家（单玩家异常不影响整体重置）
        foreach($this->getServer()->getOnlinePlayers() as $players){
            try{
                $players->teleport($this->getServer()->getDefaultLevel()->getSafeSpawn());
                $config = new Config($this->getDataFolder() . "Teams/" . $players->getName() . ".yml", Config::YAML);
                $players->setGamemode(2); // 游戏结束后恢复玩家到"可进房开局"的游戏状态，避免 gamemode 残留导致 $i 统计不到
                $players->setHealth(20);
                $players->setMaxHealth(20);
                $players->removeAllEffects();
                $players->getInventory()->clearAll();
                $players->setDisplayName(TF::WHITE . $players->getName());
                KitWarsIntegration::restoreTitle($players);
                $config->set("team", "null");
                $config->set("kit", "null");
                $config->set("timeStoper", 0);
                $config->save();
            }catch(\Exception $e){
                $this->getLogger()->warning("重置玩家状态失败: " . $e->getMessage());
            }
        }
    }

    /**
     * 胜利结算（由 gameStartTask 检测到只剩一个存活队伍时调用）
     * 流程：统计存活队伍 → 若只剩一种 → 宣布胜者队伍 → resetGame() 重置整局
     */
    public function gameWinEnd($winnerTeam = ""){
        if($winnerTeam == ""){
            // 兜底：未传入胜者时自行检测剩余的唯一队伍
            $array = array();
            foreach($this->getServer()->getLevelByName("kitwars")->getPlayers() as $players){
                // 只统计"身处游戏地图内"且处于游戏状态的玩家，不在 kitwars 地图的不计入胜负判断
                if($players->getLevel()->getFolderName() == "kitwars" and $players->getGamemode() == 2){
                    $config = new Config($this->getDataFolder() . "Teams/" . $players->getName() . ".yml", Config::YAML);
                    $team = $config->get("team");
                    if($team != "null" and $team != null and $team != ""){
                        $array[] = $team;
                    }
                }
            }
            if(count(array_unique($array)) == 1){
                $winnerTeam = strval($array[0]);
            }else{
                return; // 无法确定胜者，不结束游戏
            }
        }
        $teamName = ucfirst($winnerTeam);
        foreach($this->getServer()->getOnlinePlayers() as $players){
            try{
                $players->sendMessage($this->prefix . TF::GREEN . "$teamName team is winner!!!");
            }catch(\Exception $e){}
        }
        $this->resetGame(); // 统一重置整局，以便开始下一局
    }

    /**
     * 超时平局结算（由 gameStartTask 在倒计时归零时调用）
     * 提示 "No winner" 后调用 resetGame() 重置整局
     */
    public function gameEnd(){
        foreach($this->getServer()->getOnlinePlayers() as $players){
            try{
                $players->sendMessage($this->prefix . TF::BLUE . "No winner");
            }catch(\Exception $e){}
        }
        $this->resetGame(); // 统一重置整局
    }

    /**
     * 从队伍名单中移除指定玩家
     * 注：PHP 传值数组，函数内修改不会影响调用方（$this->red 等），
     *     调用方需用返回值接收，如 $this->red = $this->delTeam($this->red, $name);
     */
    public function delTeam($team, $playername){
        if(!is_array($team)){
            return $team;
        }
        foreach($team as $k => $name){
            if($name == $playername){
                unset($team[$k]); // 移除该玩家
            }
        }
        return $team; // 返回剔除后的名单
    }

    /**
     * 强制分队（开局时由 gameStartTask 对未选队玩家调用）
     * 若玩家 team=null（没点羊毛），自动分到人数较少的队伍（红/蓝），
     * 并染色名字、清空背包、提示玩家。
     */
    public function forceTeam(Player $player){
        $name = $player->getName();
        $config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
        $options = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        if($config->get("team") == "null"){
            if(count($this->red) != $options->get("Count") and count($this->red) <= count($this->blue)){
                $this->red[$name] = $name;
				$config->set("team", "red");
				$config->save();
				$player->setDisplayName(TF::RED.$name);
				$player->getInventory()->clearAll();
                $player->sendMessage($this->prefix . TF::RED . "由于你未选队，强制加入红队！");
            }/*elseif(count($this->yellow) != $options->get("Count")){
                $this->yellow[$name] = $name;
				$config->set("team", "yellow");
				$config->save();
				$player->setDisplayName(TF::YELLOW . $name);
				$player->getInventory()->clearAll();
                $player->sendMessage($this->prefix . TF::YELLOW . "由于你未选队，强制加入黄队！");
            }elseif(count($this->green) != $options->get("Count")){
                $this->green[$name] = $name;
				$config->set("team", "green");
				$config->save();
				$player->setDisplayName(TF::GREEN . $name);
				$player->getInventory()->clearAll();
                $player->sendMessage($this->prefix . TF::GREEN . "由于你未选队，强制加入绿队！");
            }*/else{
                $this->blue[$name] = $name;
				$config->set("team", "blue");
				$config->save();
				$player->setDisplayName(TF::BLUE.$name);
				$player->getInventory()->clearAll();
                $player->sendMessage($this->prefix . TF::BLUE . "由于你未选队，强制加入蓝队！");
            }
        }
    }

    /**
     * 开局均衡分队：把游戏房间内所有玩家尽可能均分到红/蓝两队
     * 由 gameStart() 在开局时调用。规则：优先保留玩家手动选的队，
     * 仅当某队人数超过目标（另一队不足）时才调整到人数少的队伍，
     * 保证两队人数差不超过 1（人数为奇数时红队多一人）。
     * 同时更新各自 Teams 配置、名字颜色，并对"未选队/被调整"的玩家发提示。
     */
    public function balanceTeams(){
        $players = array();
        foreach($this->getServer()->getLevelByName("kitwars")->getPlayers() as $player){
            if($player->getGamemode() == 2){
                $players[] = $player;
            }
        }
        $count = count($players);
        $redTarget = (int) ceil($count / 2);   // 目标红队人数（奇数时红队多一人）
        $blueTarget = (int) floor($count / 2); // 目标蓝队人数

        $this->red = array();
        $this->blue = array();
        foreach($players as $player){
            $name = $player->getName();
            $config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
            $team = $config->get("team");
            $oldTeam = ($team == null) ? "null" : $team;

            // 分配规则：优先保留原队（未超过目标人数），否则进人数不足的队伍
            $assign = null;
            if($team == "red" and count($this->red) < $redTarget){
                $assign = "red"; // 原队红 且 未超目标 → 保留
            }elseif($team == "blue" and count($this->blue) < $blueTarget){
                $assign = "blue"; // 原队蓝 且 未超目标 → 保留
            }elseif(count($this->red) < $redTarget){
                $assign = "red"; // 红队未满 → 补红
            }elseif(count($this->blue) < $blueTarget){
                $assign = "blue"; // 蓝队未满 → 补蓝
            }else{
                $assign = "red"; // 兜底
            }

            if($assign == "red"){
                $this->red[$name] = $name;
                $player->setDisplayName(TF::RED . $name);
            }else{
                $this->blue[$name] = $name;
                $player->setDisplayName(TF::BLUE . $name);
            }
            $config->set("team", $assign);
            $config->save();

            if($oldTeam == "null"){
                $player->sendMessage($this->prefix . TF::RED . "由于你未选队，自动加入" . ($assign == "red" ? TF::RED . "红队" : TF::BLUE . "蓝队") . "！");
            }elseif($oldTeam != $assign){
                $player->sendMessage($this->prefix . TF::GRAY . "开局已按人数均衡分队，你被调整到" . ($assign == "red" ? TF::RED . "红队" : TF::BLUE . "蓝队") . "！");
            }
        }
    }
}

