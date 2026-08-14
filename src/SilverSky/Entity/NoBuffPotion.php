<?php
namespace SilverSky\Entity;

use pocketmine\level\format\FullChunk;
use pocketmine\level\particle\SpellParticle;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\entity\ThrownPotion;
use pocketmine\entity\Entity;
use pocketmine\Player;
use pocketmine\item\Potion;
use pocketmine\entity\Projectile;

/**
 * ============ NoBuffPotion：无增益药水（自定义投掷药水）============
 * extends ThrownPotion（核心的投掷药水基类）。
 * 区别：落地时不施加原药水效果，而是只播放对应颜色的粒子特效（SpellParticle），
 * 用于技能表现（如魔法使"逃逸"四瓶药水护体、狂战士"原初[战争]"药水环绕）。
 * 通过 NBT 的 PotionId 决定颜色与类别。
 */
class NoBuffPotion extends ThrownPotion{

	public $width = 0.25;
	public $length = 0.25;
	public $height = 0.25;

	protected $gravity = 0;
	protected $drag = 1;

	public function __construct(FullChunk $chunk, CompoundTag $nbt, Entity $shootingEntity = null){
		if(!isset($nbt->PotionId)){
			$nbt->PotionId = new ShortTag("PotionId", Potion::AWKWARD);
		}

		parent::__construct($chunk, $nbt, $shootingEntity);
		Entity::registerEntity(NoBuffPotion::class);
		unset($this->dataProperties[self::DATA_SHOOTER_ID]);
		$this->setDataProperty(self::DATA_POTION_ID, self::DATA_TYPE_SHORT, $this->getPotionId());
	}

	public function getPotionId() : int{
		return (int) $this->namedtag["PotionId"];
	}

	public function kill(){
		if($this->isAlive()) {
			$color = Potion::getColor($this->getPotionId());
			$this->getLevel()->addParticle(new SpellParticle($this, $color[0], $color[1], $color[2]));


			Entity::kill();
		}
	}

	public function onUpdate($currentTick){
		if($this->closed){
			return false;
		}

		$this->timings->startTiming();

		$hasUpdate = parent::onUpdate($currentTick);

		$this->age++;

		if($this->age > 1200 or $this->isCollided){
			$this->kill();
			$this->close();
			$hasUpdate = true;
		}

		$this->timings->stopTiming();

		return $hasUpdate;
	}

	public function spawnTo(Player $player){
		$pk = new AddEntityPacket();
		$pk->type = self::NETWORK_ID;
		$pk->eid = $this->getId();
		$pk->x = $this->x;
		$pk->y = $this->y;
		$pk->z = $this->z;
		$pk->speedX = $this->motionX;
		$pk->speedY = $this->motionY;
		$pk->speedZ = $this->motionZ;
		$pk->metadata = $this->dataProperties;
		$player->dataPacket($pk);

		parent::spawnTo($player);
	}
}