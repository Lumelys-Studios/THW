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
 * ============ CommandHandler：指令处理 ============
 * 处理 /hub（回大厅）与 /kits（加入游戏/选择职业）两个指令。
 * 核心 API：
 *   - onCommand(CommandSender, Command, ...)：由服务器核心调用，$sender 可能是玩家或控制台
 *   - $command->getName()：当前执行的指令名
 *   - getDefaultLevel()->getSafeSpawn()：默认世界安全出生点
 * 说明：指令权限在 plugin.yml 中声明（lobby.command / kits.command，默认所有人可用）。
 */
trait CommandHandler
{
	public function onCommand(CommandSender $sender, Command $command, $label, array $args){
		// ---------- /thw 管理指令（玩家/控制台均可使用，权限 thw.forcestart）----------
		if($command->getName() == "thw"){
			if(!$sender->hasPermission("thw.forcestart")){
				$sender->sendMessage($this->prefix . TF::RED . "你没有权限使用该指令！");
				return true;
			}
			if(!isset($args[0]) or strtolower($args[0]) != "forcestart"){
				$sender->sendMessage($this->prefix . TF::RED . "用法: /thw forcestart");
				return true;
			}
			$this->forceStartGame($sender); // 强制开始游戏（逻辑见 GameManager）
			return true;
		}
		if($sender instanceof Player){ // 仅玩家可执行
			// 兜底：kitwars 地图未加载时自动重载，避免下方 getLevelByName 返回 null 导致指令报错、
			// 玩家无法进入等待大厅（倒计时自然无法开始）
			if($this->getServer()->getLevelByName("kitwars") === null){
				$this->getServer()->loadLevel("kitwars");
			}
			$i = 1; // 统计当前游戏房间人数（从 1 开始，实际+1）
			foreach($this->getServer()->getLevelByName("kitwars")->getPlayers() as $player){
				if($player->getGamemode() == 2){
					$i++;
				}
			}
			$name = $sender->getName();
			$options = new Config($this->getDataFolder() . "config.yml", Config::YAML);
			$config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
			switch($command->getName()){
                case "hub": // 回大厅：传送出生点、清背包、退1v1队列、清装备、重置生命/模式
                    // ---- 关键：先彻底退出本插件对局（内存队伍名单 + 配置文件）。
                    // 放在最前面无条件执行，保证即使后面的外部插件调用异常，
                    // 玩家也已经退出游戏，不会残留 team/kit 数据影响下一局 ----
                    $this->red    = $this->delTeam($this->red, $name);
                    $this->yellow = $this->delTeam($this->yellow, $name);
                    $this->green  = $this->delTeam($this->green, $name);
                    $this->blue   = $this->delTeam($this->blue, $name);
                    $file = $this->getDataFolder() . "Teams/" . "$name.yml";
                    if(is_file($file)){
                        @unlink($file); // 重进时 onPlayerJoin 会重建
                    }
                    // ---- 回城流程 ----
					$level = $this->getServer()->getDefaultLevel();
                    if($level !== null){
                        $sender->teleport($level->getSafeSpawn());
                    }
        			$sender->getInventory()->clearAll();
                    $sender->removeAllEffects();
        			$sender->setFood(20);
        			$sender->setMaxHealth(20);
        			$sender->setHealth(20);
        			$sender->setGamemode(2);
                    // ---- 外部插件整合（异常只记日志，绝不能中断上面的退出流程）----
                    try{
                        KitWarsIntegration::leaveDuelQueue($sender); // 退出 Advanced1vs1 匹配队列/决斗（含孤儿决斗）
                    }catch(\Exception $e){
                        $this->getLogger()->warning("退出1v1队列失败: " . $e->getMessage());
                    }
                    try{
                        KitWarsIntegration::clearPlayerKit($sender); // 清除 KitKB 装备残留
                    }catch(\Exception $e){
                        $this->getLogger()->warning("清除KitKB装备失败: " . $e->getMessage());
                    }
                    // 接入 PureChat 称号：恢复玩家的前缀/名字标签（异常不影响退出对局）
                    try{
                        $sender->setDisplayName(TF::WHITE.$name);
                        KitWarsIntegration::restoreTitle($sender);
                    }catch(\Exception $e){
                        $this->getLogger()->warning("恢复玩家称号失败: " . $e->getMessage());
                    }
                    break;
				case "kits": // 加入游戏/选择职业（仅游戏未开始时可用）
				if(isset($args[0]) and $options->get("Start") == 0){
					switch(strval($args[0])){
						case "join": // 加入游戏房间（人数上限16）
						if($i < 16){
                            // 为每个使用 join 的玩家无条件设定满足倒计时的状态：
                            // 必须在 kitwars 地图、gamemode=2（gameStartTask 按 gamemode==2 统计倒计时人数 $i）
                            if($sender->getLevel() != $this->getServer()->getLevelByName("kitwars")){
							$sender->teleport($this->getServer()->getLevelByName("kitwars")->getSafeSpawn());
							foreach($this->getServer()->getLevelByName("kitwars")->getPlayers() as $player){
                                $player->sendMessage($this->prefix . TF::RED . "$name 加入了职业战争！" . TF::GREEN . " [$i/16]");
                            }
                            }else{
                                $sender->sendMessage($this->prefix . TF::RED . "你已经在游戏房间里了！");
                            }
                            // 无条件设定：清理 1v1 等其他插件残留 + gamemode=2 + 补发选队羊毛 + 初始化职业
                            // 玩家从 Advanced1vs1 决斗（gamemode 被设为 0）等场景回来后，必须彻底复位才能被职业战争正常统计/传送
                            $sender->setGamemode(2);
                            $sender->removeAllEffects();
                            $sender->getInventory()->clearAll();
                            KitWarsIntegration::leaveDuelQueue($sender); // 退出 Advanced1vs1 匹配队列/决斗（含孤儿决斗）
                            KitWarsIntegration::clearPlayerKit($sender); // 清除 KitKB 装备残留
							$this->teamGive($sender);
                            $config->set("kit", "miko");
                            $config->save();
                        }else{
							$sender->sendMessage($this->prefix . TF::RED . "游戏已满人！");
						}
							break;
						case "archer":
                            $sender->sendMessage($this->prefix . TF::GREEN . "你已选择职业'弓兵'！");
							$config->set("kit", "archer");
							$config->save();
							break;
						case "saber":
                            $sender->sendMessage($this->prefix . TF::GREEN . "你已选择职业'剑士'！");
							$config->set("kit", "saber");
							$config->save();
							break;
						case "mage":
                            $sender->sendMessage($this->prefix . TF::GREEN . "你已选择职业'魔法使'！");
							$config->set("kit", "mage");
							$config->save();
							break;
						case "berserker":
                            $sender->sendMessage($this->prefix . TF::GREEN . "你已选择职业'狂战士'！");
							$config->set("kit", "berserker");
							$config->save();
							break;
						case "miko":
                            $sender->sendMessage($this->prefix . TF::GREEN . "你已选择职业'巫女'！");
							$config->set("kit", "miko");
							$config->save();
							break;
                        case "vampire":
                            $sender->sendMessage($this->prefix . TF::GREEN . "你已选择职业'吸血鬼'！");
							$config->set("kit", "vampire");
							$config->save();
							break;
                        case "timer":
                            $sender->sendMessage($this->prefix . TF::GREEN . "你已选择职业'从者'！");
							$config->set("kit", "timer");
							$config->save();
							break;
                        case "kittest": // 调试：直接发放职业装备
                            $this->kitGive($sender);
                            break;
                        case "teamtest":
                            $this->teamGive($sender);
                            break;
                        default:
                            $sender->sendMessage($this->prefix . TF::RED . "指令:/kit [join|kits]");
                        	break;
					}
				}else{
					$sender->sendMessage($this->prefix . TF::RED . "游戏已开始！你不能那么做了！");
				}
			}
		}else{ // 控制台无法执行本插件指令
			$sender->sendMessage("控制台你玩个几把");
		}
	}
}

