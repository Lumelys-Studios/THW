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
 * ============ CombatListener：战斗伤害与职业战斗技能 ============
 * 处理玩家受伤事件（EntityDamageEvent），主要做两件事：
 *   1) 实时更新玩家头顶的"职业+血量"名字标签（限速：每 11 tick 才刷新一次）
 *   2) 玩家攻击玩家（EntityDamageByEntityEvent）时，按攻击者职业 + 手持物品
 *      触发对应职业技能（符卡/武器特效），并阻止队友伤害。
 * 冷却机制：用 $this->lengque["技能名"][玩家名] 标记冷却，配合 CallbackTask
 *           [\$this,"remove"] 在指定 tick 后自动清除冷却标记。
 */
trait CombatListener
{
	public function onHit(EntityDamageEvent $ev){
        		$player = $ev->getEntity();
        		if($player instanceof Player){
        			$damage = $ev->getFinalDamage();
                	$health = $player->getHealth() - $damage;
                	$maxHealth = $player->getMaxHealth();
                	$name = $player->getName();
                	$config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
                	$kit = $config->get("kit");
                    if($player->getLevel()->getFolderName() == "kitwars"){
                        if(isset($this->lengque["prefix"][$name])){}else{
                            $this->lengque["prefix"][$name] = 0;
                        	$this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["prefix", $name]), 11);
                		$job = $this->getJobName($kit);

                		if($config->get("team") == "red"){
                        	$player->setNameTag(TF::DARK_GRAY . "[" . TF::RED. $job . TF::WHITE . " $health/$maxHealth" . TF::DARK_GRAY . "]" . TF::RED . $name);
                    	}elseif($config->get("team") == "blue"){
                        	$player->setNameTag(TF::DARK_GRAY . "[" . TF::BLUE. $job . TF::WHITE . " $health/$maxHealth" . TF::DARK_GRAY . "]" . TF::BLUE . $name);
                        	}
                    	}
            		}
                }
		// ---------- 第二部分：玩家攻击玩家时的职业技能处理 ----------
		// EntityDamageByEntityEvent 是"被实体伤害"事件，getEntity()=被打者, getDamager()=攻击者
		if($ev instanceof EntityDamageByEntityEvent){
			if($ev->getEntity() instanceof Player and $ev->getDamager() instanceof Player){ // 双方都是玩家
                $options = new Config($this->getDataFolder() . "config.yml", Config::YAML);
				$player = $ev->getEntity(); // 被打者
                $x = $player->x;
                $y = $player->y;
                $z = $player->z;
                $level = $player->getLevel();
                $chunk = $level->getChunk(round($x) >> 4, round($z) >> 4); // 被打者所在区块（生成闪电用）
                $nbt = new CompoundTag("", [ // 实体 NBT：坐标/速度/朝向（核心的结构化实体数据）
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $x),
				new DoubleTag("", $y),
				new DoubleTag("", $z)
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", 0),
				new DoubleTag("", 0),
				new DoubleTag("", 0)
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", lcg_value() * 360),
				new FloatTag("", 0)
			]),
		]);
				$pn = $player->getName();
				$p = new Config($this->getDataFolder() . "Teams/" . "$pn.yml", Config::YAML);
				$damager = $ev->getDamager();
				$dn = $damager->getName();
                $dh = $damager->getInventory()->getItemInHand()->getId();
                $dhn = $damager->getInventory()->getItemInHand()->getCustomName();
				$d = new Config($this->getDataFolder() . "Teams/" . "$dn.yml", Config::YAML);
                if($damager->getLevel()->getFolderName() == "kitwars"){ // 双方都在游戏地图内才判定
                if($options->get("timeStop") == 0){ // 不在时停期间
                if($options->get("Start") == 1){ // 游戏已开始
				if($d->get("team") == $p->get("team")){ // 打到了同队玩家
					if($p->get("team") != "null"){
						$ev->setCancelled(); // 取消伤害（防队友误伤）
						$damager->sendPopup($this->prefix . TF::RED . "不要攻击你的队友！");
					}
				}elseif($d->get("team") != $p->get("team") and $p->get("team") != "null"){ // 敌对玩家
                    if($d->get("kit") == "miko"){ // 攻击者是"巫女"
                		if($dh == 339 and $dhn == TF::DARK_PURPLE."符卡 [雷击](攻击召唤闪电重创目标,CD15s)"){ // 手持"符卡[雷击]"
                    		if(isset($this->lengque["lightning"][$dn])){ // 冷却中
                        		$ev->setCancelled();
                        		$damager->sendMessage($this->prefix . TF::RED . "符卡 [雷击] 技能冷却中(CD 15s)");
                    		}else{ // 可释放
                        	$this->lengque["lightning"][$dn] = 0; // 标记进入冷却
                        	$this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["lightning", $dn]), 20 * 15); // 15秒后清除冷却标记
                            $damager->addEffect(Effect::getEffect(11)->setDuration(20*1)->setAmplifier(10)->setVisible(true)); // 急迫效果（短暂加速）
                            $damager->addEffect(Effect::getEffect(12)->setDuration(20*8)->setAmplifier(0)->setVisible(true)); // 迅捷效果
                    		$entity = Entity::createEntity(93, $chunk, $nbt); // 93=闪电实体ID，在被打者位置生成闪电
                    		$entity->spawnToAll(); // 对所有在线玩家可见
                        	$value = 0;
                        	foreach($player->getInventory()->getArmorContents() as $armor => $i){
                            	if($i->isArmor()){
                                	$value += $i->getArmorValue();
                            	}
                        	}
                                $damage = $value*1.45;
								if($damage >= 22){
                                    $ev->setDamage($damage);
                                }else{
                                    $ev->setDamage(7);
                                }
                            }
                        }
                        if($dh == 280 and $dhn == TF::WHITE."御币(普攻固定6伤)"){
                            $ev->setDamage(6);
                        	}
                        }elseif($d->get("kit") == "berserker"){ // 攻击者是"狂战士"
                        if($dh == 258 and $dhn == TF::YELLOW."人里 [战斧](攻击造成高额伤害并短暂禁锢)"){ // 手持战斧
                            if(isset($this->lengque["waraxe"][$dn])){ // 冷却中
                                $damager->sendMessage($this->prefix . TF::RED . "人里 [战斧] 技能冷却中(CD 5s)");
                                $ev->setCancelled();
                            }else{ // 触发：高额伤害+原地禁锢（把目标传送回原坐标实现"定身"）
                            	$this->lengque["waraxe"][$dn] = 0;
                        		$this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["waraxe", $dn]), 20 * 5); // 5秒冷却
                                $ev->setDamage(12); // 造成 12 点伤害
                                $damager->sendMessage($this->prefix . TF::GOLD . "技能发动，击中 " . $pn);
                                $pos = new Vector3($player->x, $player->y, $player->z);
                                $player->teleport($pos); // 禁锢：拉回原位
                            }
                        }
                    }elseif($d->get("kit") == "saber"){ // "剑士"
                        if($dh == 276 and $dhn == TF::YELLOW."石中剑(普攻固定10伤)"){ // 手持石中剑，固定 10 点伤害
                            $ev->setDamage(10);
                        }
                    }elseif($d->get("kit") == "vampire"){ // "吸血鬼"
                        if($dh == 370 and $dhn == TF::RED."恶魔之牙(普攻固定8伤)"){ // 手持恶魔之牙
                            $ev->setDamage(8); // 固定 8 点伤害
                            $blood = $ev->getFinalDamage() * 0.7; // 吸血：吸取最终伤害的 70%
                            if(isset($this->lengque["blood"][$dn])){ // 极短冷却（5 tick）防刷血

                            	}else{
                                $this->lengque["blood"][$dn] = 0;
                        		$this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["blood", $dn]), 5);
                                if($blood > 2){ // 单次吸血上限 2 点，避免失衡
                                	$blood = 2;
                                	$damager->setHealth($damager->getHealth() + $blood); // 攻击者回血
                            	}else{
                                	$damager->setHealth($damager->getHealth() + $blood);
                                	}
                            	}
                        	}
                        }elseif($d->get("kit") == "timer"){ // "从者"
                        if($dh == 267 and $dhn == TF::WHITE."匕首(普攻固定8伤)"){ // 手持匕首
                            $ev->setDamage(8);
                            $rand = mt_rand(1, 100); // 1% 概率触发被动
                            if($rand == 1){
                                $player->teleport(new Vector3($player->x, $player->y, $player->z)); // "禁锢"被动：拉回原位
                                $damager->sendMessage($this->prefix . TF::GRAY . "被动触发");
                            }
                        }
                    }
                	}
                }else{
                    $ev->setCancelled();
                    $damager->sendMessage($this->prefix . TF::RED . "主播别急，游戏没开始呢");
                	}
                }elseif($options->get("timeStop") == 1){ // 时停期间
                    // 判断攻击者是否被时停定身：在 stopPos 半径 6 格内，且不是时停者本人（从者）
                    $isStopper = ($d->get("kit") == "timer" and $d->get("timeStoper") == 1);
                    $inStopRange = isset($this->stopPos) and $damager->distance($this->stopPos) <= 6;
                    if($inStopRange and !$isStopper){ // 被定身：无法攻击
                        $ev->setCancelled();
                        $damager->sendPopup($this->prefix . TF::RED . "时停中，你被定身了！");
                    }else{ // 未被定身（范围外 或 时停者本人）：可正常攻击
                        if($options->get("Start") == 1){
                            if($d->get("team") == $p->get("team") and $p->get("team") != "null"){ // 同队
                                $ev->setCancelled();
                                $damager->sendPopup($this->prefix . TF::RED . "不要攻击你的队友！");
                            }elseif($d->get("team") != $p->get("team") and $p->get("team") != "null"){ // 敌对
                                if($d->get("kit") == "timer" and $d->get("timeStoper") == 1 and $dh == 267 and $dhn == TF::WHITE."匕首(普攻固定8伤)"){
                                    $ev->setDamage(7); // 保留原时停从者匕首伤害
                                }
                            }else{ // 未分队
                                $ev->setCancelled();
                                $damager->sendPopup($this->prefix . TF::RED . "不要攻击你的队友！");
                            }
                        }else{ // 游戏未开始
                            $ev->setCancelled();
                            $damager->sendMessage($this->prefix . TF::RED . "主播别急，游戏没开始呢");
                        }
                    }
                }else{ // 兜底
                    $ev->setCancelled();
                    $damager->sendPopup($this->prefix . TF::RED . "不要攻击你的队友！");
                }
			}
		}
	}
}
}

