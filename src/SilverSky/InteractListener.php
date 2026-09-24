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
use pocketmine\block\Block;
use pocketmine\level\sound\AnvilFallSound;
use pocketmine\level\sound\BlockBreakSound;
use pocketmine\level\particle\DestroyBlockParticle;
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
 * ============ InteractListener：右键交互技能 + 队伍选择 ============
 * 处理玩家右键事件（PlayerInteractEvent），两大功能：
 *   1) 手持各职业的技能道具（符卡/武器等）右键 → 释放技能（投影弹/药水/效果/时停）
 *   2) 手持羊毛选队道具右键 → 加入红/黄/绿/蓝队（当前启用红蓝两队）
 * 所有技能都有冷却：$this->lengque["技能名"][玩家名] 标记，到期自动清除。
 * 核心 API：
 *   - $ev->getItem()       手持物品（getDamage()=子ID, getCustomName()=自定义名）
 *   - new FireBall/BigFireBall/ThrownPotion(区块, NBT, 发射者) 生成飞行物
 *   - Effect::getEffect(id) 药水效果，addEffect() 施加到玩家
 *   - addParticle()/addSound() 播放粒子特效/声音
 */
trait InteractListener
{
	/**
	 * 右键事件统一入口：先按"技能道具"判定（需游戏未开始时停、且是该职业），
	 * 再按"选队羊毛"判定。
	 * 物品 ID 参考：339=火药(符卡), 340=空桶(炮符), 341=木桶(逃逸), 286=铁铲(战争),
	 * 283=金铲(冥想/莱瓦汀), 331=线(嗜血), 347=指南针(怀表), 35=羊毛(选队)
	 */
	public function onTeam(PlayerInteractEvent $ev){
		$player = $ev->getPlayer();
		$name = $player->getName();
		$config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
		$options = new Config($this->getDataFolder() . "config.yml", Config::YAML);
		$kit = $config->get("kit");
		$item = $ev->getItem();
    	$itemId = $item->getId();
		$itemName = $item->getCustomName();
        // 时停状态自愈：config 标记"时停中"，但实际没有进行中的时停任务（任务异常/结束未复位）
        // → 立即复位 timeStop，避免时停结束后玩家右键交互（技能）永久失效
        if($options->get("timeStop") == 1 and !isset($this->stopTime)){
            $options->set("timeStop", 0);
            $options->save();
        }
        // 技能可用条件：不在时停，或 时停中但玩家在时停范围外（未被定身）也可正常使用技能
        $canUseSkill = $options->get("timeStop") == 0 or (isset($this->stopPos) and $player->distance($this->stopPos) > 6);
        if($canUseSkill){
            // ---- 巫女：符卡 [火球]（投掷火球）----
            if($itemId == 339 and $itemName == TF::DARK_PURPLE."符卡 [火球](右键投掷火球,CD2s)" and $kit == "miko"){
                if(isset($this->lengque["fireball"][$name])){
                    $ev->setCancelled();
                    $player->sendMessage($this->prefix . TF::RED . "符卡 [火球] 冷却中(CD 2s)");
                }else{
                    $this->lengque["fireball"][$name] = 0;
                    $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["fireball", $name]),20 * 2);
                $fb = new FireBall($player->level->getChunk($player->x >> 4, $player->z >> 4), new CompoundTag("", [
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $player->x),
				new DoubleTag("", $player->y + $player->getEyeHeight()),
				new DoubleTag("", $player->z)
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", -sin($player->yaw / 180 * M_PI)  * cos($player->pitch / 180 * M_PI)),
				new DoubleTag("", -sin($player->pitch / 180 * M_PI)),
				new DoubleTag("", cos($player->yaw / 180 * M_PI) * cos($player->pitch / 180 * M_PI))
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", $player->yaw),
				new FloatTag("", $player->pitch)
			]),
			]),$player,1200);
                $fb->spawnToAll();
                $fb->setMotion($fb->getMotion()->multiply(self::motion));
                }
            }
        	// ---- 魔法使：炮符 [大火球]（大号爆炸火球）----
        	if($itemId == 340 and $itemName == TF::AQUA."炮符 [大火球](投掷爆炸火球,CD5s)" and $kit == "mage"){
                if(isset($this->lengque["bigfireball"][$name])){
                    $ev->setCancelled();
                    $player->sendMessage($this->prefix . TF::RED . "炮符 [大火球] 冷却中(CD 5s)");
                }else{
                    $this->lengque["bigfireball"][$name] = 0;
                    $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["bigfireball", $name]),20 * 5);
                $fb = new BigFireBall($player->level->getChunk($player->x >> 4, $player->z >> 4), new CompoundTag("", [
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $player->x),
				new DoubleTag("", $player->y + $player->getEyeHeight()),
				new DoubleTag("", $player->z)
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", -sin($player->yaw / 180 * M_PI)  * cos($player->pitch / 180 * M_PI)),
				new DoubleTag("", -sin($player->pitch / 180 * M_PI)),
				new DoubleTag("", cos($player->yaw / 180 * M_PI) * cos($player->pitch / 180 * M_PI))
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", $player->yaw),
				new FloatTag("", $player->pitch)
			]),
			]),$player,1200);
                $fb->length = 0.5;
                $fb->height = 0.5;
                $fb->width = 0.5;
                $fb->damage = 15;
                $fb->spawnToAll();
                $fb->setMotion($fb->getMotion()->multiply(1.8));
                }
            }
        // ---- 魔法使：废魔 [深层生态炸弹]（投掷伤害药水）----
        if($itemId == 340 and $itemName == TF::AQUA."废魔 [深层生态炸弹](投掷伤害药水,CD6s)" and $kit == "mage"){
                if(isset($this->lengque["boom"][$name])){
                    $ev->setCancelled();
                    $player->sendMessage($this->prefix . TF::RED . "废魔 [深层生态炸弹] 冷却中(CD 6s)");
                }else{
                    $this->lengque["boom"][$name] = 0;
                    $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["boom", $name]),20 * 6);
                $pot = new ThrownPotion($player->level->getChunk($player->x >> 4, $player->z >> 4), new CompoundTag("", [
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $player->x),
				new DoubleTag("", $player->y + $player->getEyeHeight()),
				new DoubleTag("", $player->z)
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", -sin($player->yaw / 180 * M_PI)  * cos($player->pitch / 180 * M_PI)),
				new DoubleTag("", -sin($player->pitch / 180 * M_PI)),
				new DoubleTag("", cos($player->yaw / 180 * M_PI) * cos($player->pitch / 180 * M_PI))
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", $player->yaw),
				new FloatTag("", $player->pitch)
			]),
            "PotionId" => new ShortTag("PotionId", Potion::HARMING)
			]),$player);
                $pot->spawnToAll();
                // 投掷速度倍率：multiply() 里的数值越大，药水飞得越远（改这里即可调整投掷距离）
                $pot->setMotion($pot->getMotion()->multiply(2));
                }
            }
        	// ---- 魔法使：逃逸（四瓶无增益药水包裹自身 + 速度buff 突围）----
        	if($itemId == 341 and $itemName == TF::AQUA."逃逸(获得速度buff突围,CD10s)" and $kit == "mage"){
            	if(isset($this->lengque["run"][$name])){
                    $ev->setCancelled();
                    $player->sendMessage($this->prefix . TF::RED . "逃逸 冷却中(CD 10s)");
                }else{
                    $this->lengque["run"][$name] = 0;
                    $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["run", $name]),20 * 10);
                    
                    $pot1 = new NoBuffPotion($player->level->getChunk($player->x >> 4, $player->z >> 4), new CompoundTag("", [
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $player->x),
				new DoubleTag("", $player->y),
				new DoubleTag("", $player->z)
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", -sin($player->yaw / 180 * M_PI)  * cos($player->pitch / 180 * M_PI)),
				new DoubleTag("", -sin($player->pitch / 180 * M_PI)),
				new DoubleTag("", cos($player->yaw / 180 * M_PI) * cos($player->pitch / 180 * M_PI))
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", $player->yaw),
				new FloatTag("", $player->pitch)
			]),
            "PotionId" => new ShortTag("PotionId", Potion::HEALING)
			]),$player);
                $pot2 = new NoBuffPotion($player->level->getChunk($player->x >> 4, $player->z >> 4), new CompoundTag("", [
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $player->x),
				new DoubleTag("", $player->y),
				new DoubleTag("", $player->z)
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", -sin($player->yaw / 180 * M_PI)  * cos($player->pitch / 180 * M_PI)),
				new DoubleTag("", -sin($player->pitch / 180 * M_PI)),
				new DoubleTag("", cos($player->yaw / 180 * M_PI) * cos($player->pitch / 180 * M_PI))
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", $player->yaw),
				new FloatTag("", $player->pitch)
			]),
            "PotionId" => new ShortTag("PotionId", Potion::FIRE_RESISTANCE)
			]),$player);
                $pot3 = new NoBuffPotion($player->level->getChunk($player->x >> 4, $player->z >> 4), new CompoundTag("", [
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $player->x),
				new DoubleTag("", $player->y),
				new DoubleTag("", $player->z)
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", -sin($player->yaw / 180 * M_PI)  * cos($player->pitch / 180 * M_PI)),
				new DoubleTag("", -sin($player->pitch / 180 * M_PI)),
				new DoubleTag("", cos($player->yaw / 180 * M_PI) * cos($player->pitch / 180 * M_PI))
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", $player->yaw),
				new FloatTag("", $player->pitch)
			]),
            "PotionId" => new ShortTag("PotionId", Potion::POISON)
			]),$player);
                $pot4 = new NoBuffPotion($player->level->getChunk($player->x >> 4, $player->z >> 4), new CompoundTag("",[
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $player->x),
				new DoubleTag("", $player->y),
				new DoubleTag("", $player->z)
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", -sin($player->yaw / 180 * M_PI)  * cos($player->pitch / 180 * M_PI)),
				new DoubleTag("", -sin($player->pitch / 180 * M_PI)),
				new DoubleTag("", cos($player->yaw / 180 * M_PI) * cos($player->pitch / 180 * M_PI))
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", $player->yaw),
				new FloatTag("", $player->pitch)
			]),
            "PotionId" => new ShortTag("PotionId", Potion::SWIFTNESS)
			]),$player);
                	$pot1->spawnToAll();
                	$pot2->spawnToAll();
                	$pot3->spawnToAll();
                	$pot4->spawnToAll();
                    $player->removeAllEffects();
                    $player->addEffect(Effect::getEffect(1)->setDuration(20*4)->setAmplifier(1)->setVisible(true));
        	}
        }
        	// ---- 狂战士：原初 [战争]（四瓶药水护体 + 力量/生命/抗性buff）----
        	if($itemId == 286 and $itemName == TF::GOLD."原初 [战争](强力增益buff,CD35s)" and $kit == "berserker"){
                if(isset($this->lengque["wars"][$name])){
                    $ev->setCancelled();
                    $player->sendMessage($this->prefix . TF::RED . "原初 [战争] 冷却中(CD 35s)");
                }else{
                    $this->lengque["wars"][$name] = 0;
                    $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["wars", $name]),20 * 35);
                $pot1 = new NoBuffPotion($player->level->getChunk($player->x >> 4, $player->z >> 4), new CompoundTag("", [
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $player->x + 2),
				new DoubleTag("", $player->y),
				new DoubleTag("", $player->z)
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", -sin($player->yaw / 180 * M_PI)  * cos($player->pitch / 180 * M_PI)),
				new DoubleTag("", -sin($player->pitch / 180 * M_PI)),
				new DoubleTag("", cos($player->yaw / 180 * M_PI) * cos($player->pitch / 180 * M_PI))
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", $player->yaw),
				new FloatTag("", $player->pitch)
			]),
            "PotionId" => new ShortTag("PotionId", Potion::HEALING)
			]),$player);
                $pot2 = new NoBuffPotion($player->level->getChunk($player->x >> 4, $player->z >> 4), new CompoundTag("", [
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $player->x - 2),
				new DoubleTag("", $player->y),
				new DoubleTag("", $player->z)
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", -sin($player->yaw / 180 * M_PI)  * cos($player->pitch / 180 * M_PI)),
				new DoubleTag("", -sin($player->pitch / 180 * M_PI)),
				new DoubleTag("", cos($player->yaw / 180 * M_PI) * cos($player->pitch / 180 * M_PI))
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", $player->yaw),
				new FloatTag("", $player->pitch)
			]),
            "PotionId" => new ShortTag("PotionId", Potion::FIRE_RESISTANCE)
			]),$player);
                $pot3 = new NoBuffPotion($player->level->getChunk($player->x >> 4, $player->z >> 4), new CompoundTag("", [
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $player->x),
				new DoubleTag("", $player->y),
				new DoubleTag("", $player->z + 2)
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", -sin($player->yaw / 180 * M_PI)  * cos($player->pitch / 180 * M_PI)),
				new DoubleTag("", -sin($player->pitch / 180 * M_PI)),
				new DoubleTag("", cos($player->yaw / 180 * M_PI) * cos($player->pitch / 180 * M_PI))
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", $player->yaw),
				new FloatTag("", $player->pitch)
			]),
            "PotionId" => new ShortTag("PotionId", Potion::POISON)
			]),$player);
                $pot4 = new NoBuffPotion($player->level->getChunk($player->x >> 4, $player->z >> 4), new CompoundTag("",[
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $player->x),
				new DoubleTag("", $player->y),
				new DoubleTag("", $player->z - 2)
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", -sin($player->yaw / 180 * M_PI)  * cos($player->pitch / 180 * M_PI)),
				new DoubleTag("", -sin($player->pitch / 180 * M_PI)),
				new DoubleTag("", cos($player->yaw / 180 * M_PI) * cos($player->pitch / 180 * M_PI))
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", $player->yaw),
				new FloatTag("", $player->pitch)
			]),
            "PotionId" => new ShortTag("PotionId", Potion::SWIFTNESS)
			]),$player);
                $pot1->spawnToAll();
                $pot2->spawnToAll();
                $pot3->spawnToAll();
                $pot4->spawnToAll();
                $player->addEffect(Effect::getEffect(1)->setDuration(20*8)->setAmplifier(0)->setVisible(true));
                $player->addEffect(Effect::getEffect(11)->setDuration(20*8)->setAmplifier(1)->setVisible(true));
                $player->addEffect(Effect::getEffect(5)->setDuration(20*8)->setAmplifier(1)->setVisible(true));
                $player->addEffect(Effect::getEffect(10)->setDuration(20*3)->setAmplifier(0)->setVisible(true));
                $player->sendMessage($this->prefix . TF::GREEN . "原初 [战争] 技能已发动!");
                }
            }
        	// ---- 弓兵：日符 [太阳神的眷恋]（力量+速度大buff）----
        	if($itemId == 339 and $itemName == TF::GOLD."日符 [太阳神的眷恋](力量+速度大buff,CD35s)" and $kit == "archer"){
                if(isset($this->lengque["sun"][$name])){
                    $ev->setCancelled();
                    $player->sendMessage($this->prefix . TF::RED . "日符 [太阳神的眷恋] 冷却中(CD 35s)");
                }else{
                    $this->lengque["sun"][$name] = 0;
                    $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["sun", $name]),20 * 35);
                    $player->addEffect(Effect::getEffect(5)->setDuration(20*10)->setAmplifier(5)->setVisible(true));
                    $player->addEffect(Effect::getEffect(1)->setDuration(20*10)->setAmplifier(0)->setVisible(true));
                    $player->sendMessage($this->prefix . TF::RED . "日符 [太阳神的眷恋] 技能已发动!");
                }
            }
        	// ---- 剑士：特殊技 冥想（回血 4 点）----
        	if($itemId == 283 and $itemName == TF::GOLD."特殊技 冥想(回复4点生命,CD8s)" and $kit == "saber"){
                if(isset($this->lengque["think"][$name])){
                    $ev->setCancelled();
                    $player->sendMessage($this->prefix . TF::RED . "特殊技 冥想 冷却中(CD 8s)");
                }else{
                    $this->lengque["think"][$name] = 0;
                    $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["think", $name]),20 * 8);
                    $player->setHealth($player->getHealth() + 4);
                }
            }
        	// ---- 剑士：风符 [疾风的帮助]（无冷却速度buff）----
        	if($itemId == 267 and $itemName == TF::AQUA."风符 [疾风的帮助](给予速度效果)" and $kit == "saber"){
                $player->addEffect(Effect::getEffect(1)->setDuration(20*10)->setAmplifier(0)->setVisible(true));
            }
        	// ---- 弓兵：特殊技 强化（短暂力量buff）----
        	if($itemId == 339 and $itemName == TF::LIGHT_PURPLE."特殊技 强化(力量buff,CD5s)" and $kit == "archer"){
                if(isset($this->lengque["strong"][$name])){
                    $ev->setCancelled();
                    $player->sendMessage($this->prefix . TF::RED . "特殊技 强化 冷却中(CD 5s)");
                }else{
                    $this->lengque["strong"][$name] = 0;
                    $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["strong", $name]),20 * 5);
                    $player->addEffect(Effect::getEffect(5)->setDuration(20*3)->setAmplifier(1)->setVisible(true));
                }
            }
        	// ---- 吸血鬼：禁忌 [莱瓦汀]（击飞6格内敌人并造成10点真实伤害）----
        	if($itemId == 283 and $itemName == TF::GOLD."禁忌 [莱瓦汀](点地击飞附近敌人并造成高额真伤)" and $kit == "vampire"){
                if(isset($this->lengque["laevatain"][$name])){
                    $ev->setCancelled();
                    $player->sendMessage($this->prefix . TF::RED . "禁忌 [莱瓦汀] 冷却中(CD 28s)");
                }else{
                    $this->lengque["laevatain"][$name] = 0;
                    $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["laevatain", $name]),20 * 28);
                    foreach($player->getLevel()->getEntities() as $e){
                        if($e instanceof Player and $e != $player){
                            $targerName = $e->getName();
                            $targerConfig = new Config($this->getDataFolder() . "Teams/" . "$targerName.yml", Config::YAML);
                            $userTeam = $config->get("team");
                            $targetTeam = $targerConfig->get("team");
                            // 仅对不同队伍且已分队的玩家生效（同队 / 未分队 / 自身均无效）
                            if($targetTeam != "null" and $userTeam != "null" and $targetTeam != $userTeam and $e->distance(new Vector3($player->x, $player->y, $player->z)) <= 6){
                                $deltaX = $e->x - $player->x;
                                $deltaZ = $e->z - $player->z;
                                $yaw = \atan2($deltaX, $deltaZ);
                                $e->knockBack($player, 8, \sin($yaw), \cos($yaw), 1);
                                $e->setHealth($e->getHealth() - 10);
                            }
                        }
                    }
                    // ---- 泥土破碎粒子 + 音效（范围=技能半径6格）----
                    $radius = 6; // 与技能击飞范围一致
                    $dirt = Block::get(Block::DIRT);
                    $soundPlayers = $player->getLevel()->getPlayers();
                    for($angle = 0; $angle < 360; $angle += 15){
                        $rad = $angle / 180 * M_PI;
                        $player->getLevel()->addParticle(new DestroyBlockParticle(
                            new Vector3($player->x + cos($rad) * $radius, $player->y + 0.5, $player->z + sin($rad) * $radius),
                            $dirt
                        ));
                    }
                    $player->getLevel()->addSound(new BlockBreakSound(
                        new Vector3($player->x, $player->y, $player->z),
                        Block::DIRT,
                        0
                    ), $soundPlayers);
                }
            }
        	// ---- 吸血鬼：被动 嗜血（占位道具，右键仅取消事件）----
        	if($itemId == 331 and $itemName == TF::RED."被动 嗜血(普攻吸血70%,上限2点)" and $kit == "vampire"){
                $ev->setCancelled();
            }
        	// ---- 从者：血之怀表（时停 2.5 秒，半径6格内全部定身）----
        	// 触发时记录 stopTime（tick数）和 stopPos（中心点），由 GameManager::timeStop()
        	// 每秒处理：范围内非"从者/时停者"的玩家被持续拉回原位并施加缓速。
        	if($itemId == 347 and $itemName == TF::GRAY."血之怀表(时停2.5秒,半径范围6格)" and $kit == "timer" and $options->get("timeStop") == 0){
                if(isset($this->lengque["clock"][$name])){
                    $ev->setCancelled();
                    $player->sendMessage($this->prefix . TF::RED . "血之怀表 冷却中(CD 50s)");
                }else{
                    $this->stopTime = 50; // 时停固定 2.5 秒（50 tick）
                    $this->lengque["clock"][$name] = 0;
                    $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["clock", $name]),20 * 50);
                    $this->stopPos = new Vector3($player->x, $player->y, $player->z);
                    // 音效反馈（粒子特效已移除，降低时停触发瞬间的服务器负载）
                    $player->getLevel()->addSound(new AnvilFallSound($player), $this->getServer()->getLevelByName("kitwars")->getPlayers());
                    $config->set("timeStoper", 1);
                    $options->set("timeStop", 1);
                    $config->save();
                    $options->save();
                    foreach($this->getServer()->getLevelByName("kitwars")->getPlayers() as $players){
                    	$players->sendMessage($this->prefix . TF::GRAY . "$name 使用了时停!");
                    }
            	}
        	}
        	// ---- 从者：奇术 [永恒的温柔]（金锭，向前飞跃冲刺位移）----
        	if($itemId == 266 and $itemName == TF::GOLD."奇术".TF::LIGHT_PURPLE."[永恒的温柔](向前飞跃冲刺,CD20s)" and $kit == "timer"){
                if(isset($this->lengque["qishu"][$name])){
                    $ev->setCancelled();
                    $player->sendMessage($this->prefix . TF::RED . "奇术 [永恒的温柔] 冷却中(CD 20s)");
                }else{
                    $this->lengque["qishu"][$name] = 0;
                    $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "remove"], ["qishu", $name]),20 * 20);
                    // 向前飞跃：按玩家朝向给一个向前 + 向上的初速度（位移约 5-7 格）
                    $mx = -sin($player->yaw / 180 * M_PI);
                    $mz = cos($player->yaw / 180 * M_PI);
                    $player->setMotion(new Vector3($mx * 2.5, 0.4, $mz * 2.5));
                    // 音效反馈
                    $player->getLevel()->addSound(new AnvilFallSound($player));
                    $player->sendMessage($this->prefix . TF::GOLD . "奇术发动：向前冲刺！");
                }
            }
    	}
		// ---------- 队伍选择：右键对应颜色的羊毛加入队伍（当前启用红蓝两队）----------
		// 核心 API：setDisplayName() 给玩家名字染色；选队后选队道具（羊毛）留在手中，
		// 待游戏开始（GameManager::gameStart 清空背包）或返回大厅（/hub 清空背包）时才会被清除。
		if($itemId == 35 and $item->getDamage() == 4 and $itemName == TF::YELLOW."Yellow Team"){
				if(count($this->yellow) !== $options->get("Count")){
					$this->setTeam($player, "yellow"); // 统一入口：先移出旧队名单，再写入新队文件/名单/显示名
					$player->sendMessage($this->prefix . TF::YELLOW . "你加入了 黄队");
				}else{
					$player->sendMessage($this->prefix . TF::DARK_RED . "黄队已满员!");
				}
			}
			// 红队（id=35, 子id=14）—— 需与蓝队人数均衡
			if($itemId == 35 and $item->getDamage() == 14 and $itemName == TF::RED."Red Team"){
				// 计算选队后的两队人数：换队玩家会同时让红队 +1、原在蓝队则蓝队 -1
				// （不能只算 count(red)+1，否则换队时玩家被新旧两队同时计入，差距算小了）
				$afterRed   = count($this->red)  + (isset($this->red[$name])  ? 0 : 1);
				$afterBlue  = count($this->blue) - (isset($this->blue[$name]) ? 1 : 0);
				if($afterRed <= $options->get("Count") and abs($afterRed - $afterBlue) <= 1){
					$this->setTeam($player, "red"); // 统一入口：换队时自动从旧队名单移除
					$player->sendMessage($this->prefix . TF::YELLOW . "你加入了 " . TF::RED . "红队");
				}elseif($afterRed > $options->get("Count")){
					$player->sendMessage($this->prefix . TF::DARK_RED . "红队已满员!");
				}else{
					$player->sendMessage($this->prefix . TF::RED . "队伍人数差距过大，请加入人数较少的队伍！");
					$ev->setCancelled();
				}
			}
			if($itemId == 35 and $item->getDamage() == 5 and $itemName == TF::GREEN."Green Team"){
				if(count($this->green) !== $options->get("Count")){
					$this->setTeam($player, "green"); // 统一入口：先移出旧队名单，再写入新队文件/名单/显示名
					$player->sendMessage($this->prefix . TF::YELLOW . "你加入了 " . TF::GREEN . "绿队");
				}else{
					$player->sendMessage($this->prefix . TF::DARK_RED . "绿队已满员!");
				}
			}
			// 蓝队（id=35, 子id=11）—— 需与红队人数均衡
			if($itemId == 35 and $item->getDamage() == 11 and $itemName == TF::BLUE."Blue Team"){
				// 计算选队后的两队人数：换队玩家会同时让蓝队 +1、原在红队则红队 -1
				$afterBlue  = count($this->blue) + (isset($this->blue[$name])  ? 0 : 1);
				$afterRed   = count($this->red)  - (isset($this->red[$name])   ? 1 : 0);
				if($afterBlue <= $options->get("Count") and abs($afterRed - $afterBlue) <= 1){
					$this->setTeam($player, "blue"); // 统一入口：换队时自动从旧队名单移除
					$player->sendMessage($this->prefix . TF::YELLOW . "你加入了 " . TF::BLUE . "蓝队");
				}elseif($afterBlue > $options->get("Count")){
					$player->sendMessage($this->prefix . TF::DARK_RED . "蓝队已满员!");
				}else{
					$player->sendMessage($this->prefix . TF::RED . "队伍人数差距过大，请加入人数较少的队伍！");
					$ev->setCancelled();
				}
			}
    }
}


