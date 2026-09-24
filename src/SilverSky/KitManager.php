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
 * ============ KitManager：职业装备/选队道具发放 ============
 * 核心 API：
 *   - Item::get(物品ID, 子ID, 数量)：创建物品（如 35=羊毛, 280=木棍, 339=火药...）
 *   - setCustomName()：给物品设置显示名（技能靠"ID+名称"匹配）
 *   - getInventory()->setContents()/setItem()/setHelmet()...：写入玩家背包各栏位
 *   - setMaxHealth()/setHealth()：设置职业血量上限
 */
trait KitManager
{
	/**
	 * 发放"选队羊毛"：加入游戏房间后，把红/蓝两块羊毛放到背包，
	 * 右键羊毛即可选择队伍（对应 InteractListener::onTeam 选队逻辑）。
	 */
	public function teamGive(Player $player){
		$red = Item::get(35, 14, 1); // 红色羊毛（子ID 14）
        $red_n = $red->setCustomName(TF::RED."Red Team");
		$blue = Item::get(35, 11, 1); // 蓝色羊毛（子ID 11）
		$blue_n = $blue->setCustomName(TF::BLUE."Blue Team");
		$inventory = $player->getInventory();
		$inventory->setContents(array($red_n, $blue_n));
		}

	/**
	 * 按玩家已选职业发放全套装备：武器/技能道具 + 食物 + 对应护甲 + 血量上限。
	 * 背包栏位约定：0=武器, 2/3=技能道具, 7=弹药, 8=食物。
	 */
	public function kitGive(Player $player){
			$name = $player->getName();
			$inv = $player->getInventory();
			$config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
			$kit = $config->get("kit");
            $food = Item::get(396,0,64); // 64 个面包
			if($kit == "null"){
				$config->set("kit", "berserker"); // 未选职业时默认狂战士
			}
        	if($kit == "miko"){ // ===== 巫女：40血，御币+雷击/火球符卡 =====
                $player->setMaxHealth(40);
                $player->setHealth(40);
				$yb = Item::get(280,0,1);
				$yb->setCustomName(TF::WHITE."御币(普攻固定6伤)");
				$fuka1 = Item::get(339,0,1);
				$fuka1->setCustomName(TF::DARK_PURPLE."符卡 [雷击](攻击召唤闪电重创目标,CD15s)");
                $fuka2 = Item::get(339,0,1);
                $fuka2->setCustomName(TF::DARK_PURPLE."符卡 [火球](右键投掷火球,CD2s)");
				$inv->setItem(0, $yb);
                $inv->setItem(2, $fuka1);
                $inv->setItem(3, $fuka2);
                $inv->setItem(8, $food);
                $inv->setHelmet(Item::get(314,0,1));
                $inv->setChestplate(Item::get(307,0,1));
                $inv->setLeggings(Item::get(304,0,1));
                $inv->setBoots(Item::get(305,0,1));
			}elseif($kit == "mage"){ // ===== 魔法使：36血，逃逸+炮符/废魔 =====
                $player->setMaxHealth(36);
                $player->setHealth(36);
                $wuqi = Item::get(341,0,1);
                $wuqi->setCustomName(TF::AQUA."逃逸(获得速度buff突围,CD10s)");
                $fuka1 = Item::get(340,0,1);
                $fuka1->setCustomName(TF::AQUA."炮符 [大火球](投掷爆炸火球,CD5s)");
                $fuka2 = Item::get(340,0,1);
                $fuka2->setCustomName(TF::AQUA."废魔 [深层生态炸弹](投掷伤害药水,CD6s)");
                $inv->setItem(0, $wuqi);
                $inv->setItem(2, $fuka1);
                $inv->setItem(3, $fuka2);
                $inv->setItem(8, $food);
                $inv->setHelmet(Item::get(298,0,1));
                $inv->setChestplate(Item::get(315,0,1));
                $inv->setLeggings(Item::get(316,0,1));
                $inv->setBoots(Item::get(313,0,1));
            }elseif($kit == "berserker"){ // ===== 狂战士：60血（最肉），战争之斧+战斧/战争 =====
                $player->setMaxHealth(60);
                $player->setHealth(60);
                $wuqi = Item::get(279,0,1);
                $wuqi->setCustomName(TF::RED."战争之斧(近战主武器)");
                $fuka1 = Item::get(258,0,1);
                $fuka1->setCustomName(TF::YELLOW."人里 [战斧](攻击造成高额伤害并短暂禁锢)");
                $fuka2 = Item::get(286,0,1);
                $fuka2->setCustomName(TF::GOLD."原初 [战争](强力增益buff,CD35s)");
                $inv->setItem(0, $wuqi);
                $inv->setItem(2, $fuka1);
                $inv->setItem(3, $fuka2);
                $inv->setItem(8, $food);
                $inv->setHelmet(Item::get(314,0,1));
                $inv->setChestplate(Item::get(311,0,1));
                $inv->setLeggings(Item::get(308,0,1));
                $inv->setBoots(Item::get(309,0,1));
            }elseif($kit == "archer"){ // ===== 弓兵：40血，影之弓+箭矢/强化/日符 =====
                $player->setMaxHealth(40);
                $player->setHealth(40);
                $wuqi = Item::get(261,0,1);
                $wuqi->setCustomName(TF::LIGHT_PURPLE."影之弓(远程主武器)");
                $fuka1 = Item::get(339,0,1);
                $fuka1->setCustomName(TF::LIGHT_PURPLE."特殊技 强化(力量buff,CD5s)");
                $fuka2 = Item::get(339,0,1);
                $fuka2->setCustomName(TF::GOLD."日符 [太阳神的眷恋](力量+速度大buff,CD35s)");
                $bullet = Item::get(262,0,255);
                $inv->setItem(0, $wuqi);
                $inv->setItem(2, $fuka1);
                $inv->setItem(3, $fuka2);
                $inv->setItem(8, $food);
                $inv->setItem(7, $bullet);
                $inv->setHelmet(Item::get(306,0,1));
                $inv->setChestplate(Item::get(303,0,1));
                $inv->setLeggings(Item::get(308,0,1));
                $inv->setBoots(Item::get(309,0,1));
            }elseif($kit == "saber"){ // ===== 剑士：46血，石中剑+风符/冥想 =====
                $player->setMaxHealth(46);
                $player->setHealth(46);
                $wuqi = Item::get(276,0,1);
                $wuqi->setCustomName(TF::YELLOW."石中剑(普攻固定10伤)");
                $fuka1 = Item::get(267,0,1);
                $fuka1->setCustomName(TF::AQUA."风符 [疾风的帮助](给予速度效果)");
                $fuka2 = Item::get(283,0,1);
                $fuka2->setCustomName(TF::GOLD."特殊技 冥想(回复4点生命,CD8s)");
                $inv->setItem(0, $wuqi);
                $inv->setItem(2, $fuka1);
                $inv->setItem(3, $fuka2);
                $inv->setItem(8, $food);
                $inv->setHelmet(Item::get(306,0,1));
                $inv->setChestplate(Item::get(307,0,1));
                $inv->setLeggings(Item::get(308,0,1));
                $inv->setBoots(Item::get(309,0,1));
            }elseif($kit == "vampire"){ // ===== 吸血鬼：36血，恶魔之牙+嗜血/莱瓦汀 =====
                $player->setMaxHealth(36);
                $player->setHealth(36);
                $wuqi = Item::get(370,0,1);
                $wuqi->setCustomName(TF::RED."恶魔之牙(普攻固定8伤)");
                $fuka1 = Item::get(331,0,1);
                $fuka1->setCustomName(TF::RED."被动 嗜血(普攻吸血70%,上限2点)");
                $fuka2 = Item::get(283,0,1);
                $fuka2->setCustomName(TF::GOLD."禁忌 [莱瓦汀](点地击飞附近敌人并造成高额真伤)");
                $inv->setItem(0, $wuqi);
                $inv->setItem(2, $fuka1);
                $inv->setItem(3, $fuka2);
                $inv->setItem(8, $food);
                $inv->setHelmet(Item::get(302,0,1));
                $inv->setChestplate(Item::get(307,0,1));
                $inv->setLeggings(Item::get(304,0,1));
                $inv->setBoots(Item::get(313,0,1));
            }elseif($kit == "timer"){ // ===== 从者：34血，匕首+禁锢/血之怀表 =====
                $player->setMaxHealth(34);
                $player->setHealth(34);
                $wuqi = Item::get(267,0,1);
                $wuqi->setCustomName(TF::WHITE."匕首(普攻固定8伤)");
                $fuka1 = Item::get(339,0,1);
                $fuka1->setCustomName(TF::RED."被动 禁锢(每次攻击都有极小概率禁锢)");
                $fuka2 = Item::get(347,0,1);
                $fuka2->setCustomName(TF::GRAY."血之怀表(时停2.5秒,半径范围6格)");
                // 金锭：奇术 [永恒的温柔]（向前飞跃冲刺位移）
                $qishu = Item::get(266,0,1);
                $qishu->setCustomName(TF::GOLD."奇术".TF::LIGHT_PURPLE."[永恒的温柔](向前飞跃冲刺,CD20s)");
                $inv->setItem(0, $wuqi);
                $inv->setItem(2, $fuka1);
                $inv->setItem(3, $fuka2);
                $inv->setItem(4, $qishu); // 快捷栏第 5 格
                $inv->setItem(8, $food);
                $inv->setHelmet(Item::get(298,0,1));
                $inv->setChestplate(Item::get(303,0,1));
                $inv->setLeggings(Item::get(308,0,1));
                $inv->setBoots(Item::get(309,0,1));
            }
		}
}

