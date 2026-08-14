<?php

namespace SilverSky\Entity;

use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\level\format\FullChunk;
use pocketmine\entity\Entity;
use pocketmine\nbt\tag\CompoundTag;

/**
 * ============ FireBall：自定义火球实体（巫女"符卡 [火球]"）============
 * extends Projectile：服务器核心的弹射物基类，可被投出并在飞行中造成伤害。
 *   - NETWORK_ID=94：指定客户端实体ID（火球）
 *   - $damage=8：碰撞造成的伤害
 *   - 由 InteractListener::onTeam 生成，飞行 $lg 帧后或碰撞后消失
 */
class FireBall extends \pocketmine\entity\Projectile{
    
	const NETWORK_ID = 94;

	public $width = 0.3;
	public $length = 0.3;
	public $height = 0.3;

	protected $gravity = 0.03;
	protected $drag = 0.01;
    
    protected $damage = 8;

        private $lg;
        
        public function __construct(FullChunk $chunk, CompoundTag $nbt, Entity $shootingEntity = null,$lg = 1200){
                $this->lg = $lg;
                Entity::registerEntity(FireBall::class);
		parent::__construct($chunk, $nbt, $shootingEntity);
	}

        public function getName() {
            return "FireBall";
        }
        
        
                
	public function onUpdate($currentTick){
		if($this->closed){
			return false;
		}
                
                $this->timings->startTiming();
                $this->updateMovement();
		$hasUpdate = parent::onUpdate($currentTick);
                
		if($this->age > $this->lg or $this->isCollided){
			$this->kill();
			$hasUpdate = true;
		}

		$this->timings->stopTiming();

		return $hasUpdate;
	}

	public function spawnTo(\pocketmine\Player $player){
		$pk = new AddEntityPacket();
		$pk->type = FireBall::NETWORK_ID;
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

