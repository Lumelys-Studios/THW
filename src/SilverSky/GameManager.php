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
     * 并把 stopPos 半径 6 格内、非"从者/时停者"的实体持续拉回原位并施加缓速
     * （效果ID 15=缓慢），实现"时间停止"的效果。倒计时结束则解除时停并复位所有玩家的 timeStoper 标记。
     */
    public function timeStop(){
        $options = new Config($this->getDataFolder() . "config.yml", Config::YAML);
    				if($options->get("timeStop") == 1 and isset($this->stopTime) and isset($this->stopPos)){ // 处于时停状态
                        if($this->stopTime > 0){
                            $this->stopTime--;
                        	// 优化：用包围盒只查询时停范围内的实体，避免每 tick 遍历整张地图
                        	$level = $this->getServer()->getLevelByName("kitwars");
                        	$bb = new \pocketmine\math\AxisAlignedBB($this->stopPos->x - 6, $this->stopPos->y - 6, $this->stopPos->z - 6, $this->stopPos->x + 6, $this->stopPos->y + 6, $this->stopPos->z + 6);
                        	foreach($level->getNearbyEntities($bb) as $entity){
                                if($entity->distance($this->stopPos) <= 6){
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
                }elseif($config->get("timeStoper") == 1 or (isset($this->stopPos) and $player->distance($this->stopPos) > 6)){
                    // 时停中：时停者本人 + 时停范围外的自由玩家仍可回血（范围内被定身的不回）
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
		$blue = new Vector3(36, 47, 71);
			foreach($level->getPlayers() as $player){
                $player->getInventory()->clearAll();
                $this->kitGive($player);
				$name = $player->getName();
				$config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
				if($player->getGamemode() == 2){
                	$health = $player->getHealth();
                	$maxHealth = $player->getMaxHealth();
                	$kit = $config->get("kit");
                    if($player->getLevel()->getFolderName() == "kitwars"){
                		$job = $this->getJobName($kit);

                		if($config->get("team") == "red"){
                        	$player->setNameTag(TF::DARK_GRAY . "[" . TF::RED. $job . TF::WHITE . " $health/$maxHealth" . TF::DARK_GRAY . "]" . TF::RED . $name);
                    	}elseif($config->get("team") == "blue"){
                        	$player->setNameTag(TF::DARK_GRAY . "[" . TF::BLUE. $job . TF::WHITE . " $health/$maxHealth" . TF::DARK_GRAY . "]" . TF::BLUE . $name);
                    	}
            		}
					if($config->get("team") == "red"){
						$player->teleport($red);
					}elseif($config->get("team") == "blue"){
						$player->teleport($blue);
                    }
				}
			}
		}

    /**
     * 强制开始游戏（/thw forcestart 调用）
     * 准备阶段房间内玩家 ≥2 时：跳过倒计时，直接进入开始状态，
     * 对房间内玩家均衡分队（balanceTeams）+ 发放装备并传送出生点（gameStart）。
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
        foreach($this->getServer()->getLevelByName("kitwars")->getPlayers() as $player){
            if($player->getGamemode() == 2){
                $count++;
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
        try{
            $this->gameStart(); // 只调用一次：内部 balanceTeams 均衡分队 + 发放装备 + 传送出生点
        }catch(\Exception $e){
            $this->getLogger()->warning("[Touhou_Wars] 强制开局异常: " . $e->getMessage());
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
            $name = $players->getName();
            try{
                // 1) 关键状态先复位：传送出游戏地图 + 清除队伍/职业配置。
                //    放在最前面，确保即使后面的称号/外观操作异常，玩家也已经彻底退出对局
                $players->teleport($this->getServer()->getDefaultLevel()->getSafeSpawn());
                $config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
                $config->set("team", "null");
                $config->set("kit", "null");
                $config->set("timeStoper", 0);
                $config->save();
                // 2) 外观/状态类复位（单玩家异常只影响该玩家，不影响整局重置）
                $players->setGamemode(2); // 游戏结束后恢复玩家到"可进房开局"的游戏状态，避免 gamemode 残留导致 $i 统计不到
                $players->setHealth(20);
                $players->setMaxHealth(20);
                $players->removeAllEffects();
                $players->getInventory()->clearAll();
                $players->setDisplayName(TF::WHITE . $name);
                KitWarsIntegration::restoreTitle($players);
            }catch(\Exception $e){
                $this->getLogger()->warning("重置玩家状态失败: " . $e->getMessage());
            }
        }
        // 重置所有玩家配置文件：清空 Teams/ 目录全部残留
        //（在线玩家已在上方复位保存；离线玩家的残留文件一并清除，玩家重进时 onPlayerJoin 会自动重建）
        foreach(glob($this->getDataFolder() . "Teams/*") as $file){
            if(is_file($file)){
                @unlink($file);
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
                // 只统计"身处游戏地图内"且仍存活、处于游戏状态的玩家，不在 kitwars 地图的不计入胜负判断。
                // 死亡玩家不计入（即使 team 残留，也不能卡住胜负判定）
                if($players->getLevel()->getFolderName() == "kitwars" and $players->getGamemode() == 2 and $players->isAlive()){
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
     * 统一设置玩家队伍：手动选队（InteractListener 右键羊毛）与自动分配（balanceTeams）共用同一入口。
     * 保证以下三处状态始终一致，且玩家在同一时刻只会出现在一个队伍名单里（换队时先移出旧队）：
     *   1) 内存队伍名单 $this->red / $this->blue / ...
     *   2) 配置文件 Teams/<玩家名>.yml 的 team 字段
     *   3) 玩家聊天显示名（染上队伍颜色）
     * 倒计时阶段的这些修改不会残留到下一局：开局时 balanceTeams 会按配置文件重建名单，
     * 结算时 resetGame 会清空名单与配置文件，因此不影响下局游戏开始。
     * @param Player $player 玩家
     * @param string $team 目标队伍（red/yellow/green/blue）
     */
    public function setTeam(Player $player, $team){
        $name = $player->getName();
        // 先从所有队伍名单中移除（delTeam 是传值数组，必须接收返回值才真正删除）
        $this->red    = $this->delTeam($this->red, $name);
        $this->yellow = $this->delTeam($this->yellow, $name);
        $this->green  = $this->delTeam($this->green, $name);
        $this->blue   = $this->delTeam($this->blue, $name);
        // 再加入目标队伍并染色
        switch($team){
            case "red":
                $this->red[$name] = $name;
                $player->setDisplayName(TF::RED . $name);
                break;
            case "yellow":
                $this->yellow[$name] = $name;
                $player->setDisplayName(TF::YELLOW . $name);
                break;
            case "green":
                $this->green[$name] = $name;
                $player->setDisplayName(TF::GREEN . $name);
                break;
            case "blue":
                $this->blue[$name] = $name;
                $player->setDisplayName(TF::BLUE . $name);
                break;
        }
        // 写入配置文件并保存
        $config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
        $config->set("team", $team);
        $config->save();
    }

    

    /**
     * 获取职业中文名（供名字标签/提示使用）
     * @param string $kit 职业标识（mage/miko/berserker/vampire/archer/saber/timer）
     * @return string 职业中文名
     */
    public function getJobName($kit){
        switch($kit){
            case "mage":      return "魔法使";
            case "miko":      return "巫女";
            case "berserker": return "狂战士";
            case "vampire":   return "吸血鬼";
            case "archer":    return "弓兵";
            case "saber":     return "剑士";
            case "timer":     return "从者";
            default:          return "未知";
        }
    }


    /**
     * 开局均衡分队：优先保留玩家手动选的队，
     * 自动分配（补入人数较少的队伍）只对未选队的玩家生效。
     * 已手动选择红/蓝的玩家尽量保留原队；若选队后有人退出/离房导致
     * 两队人数失衡（差距超过 1 人），则强制平移人数多的一队玩家，
     * 保证开局时两队人数差不超过 1 人。
     * 由 gameStart() 在开局时调用。
     */
    public function balanceTeams(){
        $players = array();
        foreach($this->getServer()->getLevelByName("kitwars")->getPlayers() as $player){
            if($player->getGamemode() == 2){
                $players[] = $player;
            }
        }
        $this->red = array();
        $this->blue = array();
        // 第一步：已手动选队（红/蓝）的玩家完全保留原队
        foreach($players as $player){
            $name = $player->getName();
            $config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
            $team = $config->get("team");
            if($team == "red"){
                $this->setTeam($player, "red"); // 与手动选队共用同一套状态变更（数组+文件+显示名）
            }elseif($team == "blue"){
                $this->setTeam($player, "blue");
            }
        }
        // 第二步：未选队的玩家自动分到人数较少的队伍
        foreach($players as $player){
            $name = $player->getName();
            $config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
            $team = $config->get("team");
            if($team != "red" and $team != "blue"){
                if(count($this->red) <= count($this->blue)){
                    $this->setTeam($player, "red");
                    $newTeam = "red";
                }else{
                    $this->setTeam($player, "blue");
                    $newTeam = "blue";
                }
                $player->sendMessage($this->prefix . TF::RED . "由于你未选队，自动加入" . ($newTeam == "red" ? TF::RED . "红队" : TF::BLUE . "蓝队") . "！");
            }
        }
        // 第三步：最终平衡兜底 —— 选队后有人退出/离房可能让手动选择失衡（如 2:0、3:1），
        // 自动补位无法纠正已选队的玩家。这里强制从人数多的队伍平移玩家到人数少的队伍，
        // 保证两队人数差不超过 1 人（setTeam 会同步更新名单、配置文件与显示名）。
        while(abs(count($this->red) - count($this->blue)) > 1){
            if(count($this->red) > count($this->blue)){
                $moveName = array_pop($this->red); // 移走红队最后加入的玩家
                $toTeam = "blue";
            }else{
                $moveName = array_pop($this->blue);
                $toTeam = "red";
            }
            $found = false;
            foreach($players as $player){
                if($player->getName() == $moveName){
                    $this->setTeam($player, $toTeam);
                    $player->sendMessage($this->prefix . TF::RED . "为保证队伍平衡，你被调整到" . ($toTeam == "red" ? TF::RED . "红队" : TF::BLUE . "蓝队") . "！");
                    $found = true;
                    break;
                }
            }
            if(!$found){
                // 名单里出现不在房间内的玩家（正常流程不会发生），直接停止防止死循环
                break;
            }
        }
    }
}

