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
 * ============ PlayerListener：玩家生命周期事件 ============
 * 作为 trait 被 Main 主类 use 引入，方法成为 Main 的成员方法；
 * 服务器核心通过 registerEvents() 的反射机制，按事件类型自动调用这些方法。
 * 本 trait 负责：玩家加入/死亡/退出时的数据清理，以及禁止在游戏地图内丢弃物品。
 */
trait PlayerListener
{
	/**
	 * 玩家加入服务器时触发（PlayerJoinEvent）
	 * 核心 API：$ev->getPlayer() 取触发事件的玩家对象。
	 * 作用：为每位玩家建立一个独立的队伍数据文件 Teams/<玩家名>.yml，
	 *       初始化 kit（职业）、team（队伍）、timeStoper（是否时停者）三项状态。
	 */
	public function onPlayerJoin(PlayerJoinEvent $ev){
		$player = $ev->getPlayer();
		$name = $player->getName();
		@mkdir($this->getDataFolder(), 0777);		
		$this->Config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
		$this->Config->set("kit", "null");
		$this->Config->set("team", "null");
        $this->Config->set("timeStoper", 0);
		$this->Config->save();
	}

    /**
     * 玩家死亡时触发（PlayerDeathEvent）
     * 核心 API：
     *   - setDisplayName()/setNameTag()：重置头顶显示名（去掉死亡时的颜色前缀）
     *   - setDrops()：设置死亡掉落物（设为空气物品=不掉落）
     *   - setMaxHealth()：恢复最大生命值
     * 作用：在职业战争地图内死亡时，清空掉落、复位职业/队伍/时停状态，
     *       并把血量上限重置回默认 20 点（各职业开局会给更高的血量上限）。
     */
    public function onDeath(PlayerDeathEvent $ev){
        $player = $ev->getPlayer();
        $name = $player->getName();
        // 关键状态先清理：死亡玩家必须立即退出队伍。
        // 若这里漏清 team，gameStartTask 的胜负检测会一直看到 2 个队伍，
        // 导致游戏结束不了、Start 卡 1、下一局倒计时永远无法开始。
        if($player->getLevel()->getFolderName() == "kitwars"){ // 仅在职业战争地图内生效
            $ev->setDrops(array(Item::get(0,0,0))); // 禁止掉落（空气物品占位）
            $config = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
            $config->set("kit", "null");      // 清除职业
            $config->set("team", "null");     // 清除队伍
            $config->set("timeStoper", 0);    // 清除时停标记
            $config->save();
            // 同步从内存队伍名单移除，保证名单与配置文件一致（与 setTeam 的对齐原则相同）
            $this->red    = $this->delTeam($this->red, $name);
            $this->yellow = $this->delTeam($this->yellow, $name);
            $this->green  = $this->delTeam($this->green, $name);
            $this->blue   = $this->delTeam($this->blue, $name);
            $player->setMaxHealth(20); // 血量上限重置为默认 20
        }
        // 外观/称号类操作放最后并 try/catch：
        // PureChat 称号恢复一旦异常，不能反过来中断上面的队伍清理（否则会偶发卡死下一局倒计时）
        try{
            $player->setDisplayName(TF::WHITE.$name); // 复位显示名（去队伍颜色）
            // 接入 PureChat 称号：恢复玩家名字标签（而不是只显示普通名字）
            KitWarsIntegration::restoreTitle($player);
        }catch(\Exception $e){
            $this->getLogger()->warning("恢复玩家称号失败: " . $e->getMessage());
        }
    }

	/**
	 * 玩家退出服务器时触发（PlayerQuitEvent）
	 * 核心 API：getLevelByName("kitwars") 按名称获取游戏地图对象。
	 * 作用：复位该玩家的队伍数据，若他正在游戏地图内，则把他从红/黄/绿/蓝
	 *       队伍名单中移除（delTeam 见 GameManager），避免队伍计数残留。
	 */
	public function onPlayerQuit(PlayerQuitEvent $ev){
		$player = $ev->getPlayer();
		$name = $player->getName();
		// 游戏未开始（含开局倒计时 / 等待开局）时退出：直接删除玩家配置文件，
		// 防止残留的 kit/team 数据影响下一局的倒计时统计与开局（游戏异常）
		$options = new Config($this->getDataFolder() . "config.yml", Config::YAML);
		if($options->get("Start") == 0){
			$file = $this->getDataFolder() . "Teams/" . "$name.yml";
			if(is_file($file)){
				@unlink($file);
			}
		}else{
			// 游戏进行中退出：复位配置（保留文件，玩家重进时 onPlayerJoin 会重建）
			$jc = new Config($this->getDataFolder() . "Teams/" . "$name.yml", Config::YAML);
			$jc->set("kit", "null");
			$jc->set("team", "null");
	        $jc->set("timeStoper", 0);
			$jc->save();
		}
        // 退出服务器的玩家也要踢出对局与等待列表：
        // 从红/黄/绿/蓝队伍名单中移除（delTeam 是传值数组，必须接收返回值才会真正删除）
        $this->red    = $this->delTeam($this->red, $name);
        $this->yellow = $this->delTeam($this->yellow, $name);
        $this->green  = $this->delTeam($this->green, $name);
        $this->blue   = $this->delTeam($this->blue, $name);
        // 同时退出 Advanced1vs1 匹配队列/决斗（若有；异常只记日志，不影响上面已完成的退出清理）
        try{
            KitWarsIntegration::leaveDuelQueue($player);
        }catch(\Exception $e){
            $this->getLogger()->warning("退出1v1队列失败: " . $e->getMessage());
        }
	}

    /**
     * 玩家丢弃物品时触发（PlayerDropItemEvent）
     * 核心 API：setCancelled(true) 取消该事件（物品不会从背包丢出）。
     * 作用：在职业战争地图内禁止丢弃物品，防止把技能道具扔给对手/乱扔装备。
     */
    public function onDrop(PlayerDropItemEvent $ev){
        if($ev->getPlayer()->getLevel()->getFolderName() == "kitwars"){
            $ev->setCancelled(true); // 取消丢弃
        }
    }
}

