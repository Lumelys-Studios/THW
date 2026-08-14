<?php

namespace SilverSky\Entity;

use SilverSky\Entity\FireBall;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\level\format\FullChunk;
use pocketmine\entity\Entity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\level\Explosion;
use pocketmine\level\particle\PortalParticle;
use pocketmine\event\entity\ExplosionPrimeEvent;

/**
 * ============ BigFireBall：大号爆炸火球（魔法使"炮符 [大火球]"）============
 * 继承自 FireBall，区别：飞行速度更高、体积更大、碰撞后触发 Explosion 爆炸
 * （通过 ExplosionPrimeEvent 驱动核心的 Explosion 类），并点燃目标。
 *   - $damage 由外部设置（15）
 *   - explode()：在碰撞点生成爆炸（力度3）
 */
class BigFireBall extends FireBall{
    	
    	protected $gravity = 0;
        protected $drag = 0;
    
    	public $damage;
    
        private $lg;
    
    	private $dropItem = false;
        
        public function __construct(FullChunk $chunk, CompoundTag $nbt, Entity $shootingEntity = null,$lg = 1200){
                $this->lg = $lg;
                Entity::registerEntity(BigFireBall::class);
		parent::__construct($chunk, $nbt, $shootingEntity);
	}

        public function getName() {
            return "BigFireBall";
        }
        
        
                
	public function onUpdate($currentTick){
		if($this->closed){
			return false;
		}
                
                $this->timings->startTiming();
                $this->updateMovement();
		$hasUpdate = parent::onUpdate($currentTick);
                
        $this->level->addParticle(new PortalParticle($this->add(
				$this->width / 2 + mt_rand(-100, 100) / 500,
				$this->height / 2 + mt_rand(-100, 100) / 500,
				$this->width / 2 + mt_rand(-100, 100) / 500)));
		if($this->age > $this->lg or $this->isCollided){
			$this->kill();
            $this->explode();
            $this->setOnFire(true);
			$hasUpdate = true;
		}

		$this->timings->stopTiming();

		return $hasUpdate;
	}
    
    public function explode(){
        $this->server->getPluginManager()->callEvent($ev = new ExplosionPrimeEvent($this, 3, $this->dropItem));
        
        if(!$ev->isCancelled()){
            $explosion = new Explosion($this, $ev->getForce(), $this, $ev->dropItem());
            $explosion->explodeB();
        }
    }

	public function spawnTo(\pocketmine\Player $player){
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