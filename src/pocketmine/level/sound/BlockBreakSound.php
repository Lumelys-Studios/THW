<?php
namespace pocketmine\level\sound;

use pocketmine\math\Vector3;
use pocketmine\network\protocol\LevelEventPacket;

/**
 * 兼容性补丁类
 *
 * 新版 PocketMine 核心自带 pocketmine\level\sound\BlockBreakSound，
 * 但本服务器使用的旧版 Genisys 核心（MC 0.15.10）没有这个类。
 * Touhou_Wars 插件的 InteractListener 用到了它，因此在此提供等价实现，
 * 由 DevTools 的 FolderPluginLoader 把本插件 src 加入全局加载器后即可被自动加载。
 *
 * 注意：旧版客户端协议没有"方块破坏"音效事件（EVENT_SOUND_BLOCK_BREAK = 1061），
 * 这里退而使用客户端认识的"方块放置"音效事件（EVENT_SOUND_BLOCK_PLACE = 1052），
 * data 仍按方块 ID 发送（与核心自带的 BlockPlaceSound 一致），保证能听到声音。
 */
class BlockBreakSound extends GenericSound{

    /** @var int 用于编码的方块 ID */
    protected $data;

    /**
     * @param Vector3 $pos 声音播放位置
     * @param int $id 方块 ID
     * @param int $meta 方块附加值（旧协议不使用，保留以兼容新版调用方式）
     */
    public function __construct(Vector3 $pos, $id = 0, $meta = 0){
        parent::__construct($pos, LevelEventPacket::EVENT_SOUND_BLOCK_PLACE);
        $this->data = (int) $id;
    }

    /**
     * 编码为 LevelEventPacket 发送给客户端
     * @return LevelEventPacket
     */
    public function encode(){
        $pk = new LevelEventPacket;
        $pk->evid = $this->id;
        $pk->x = $this->x;
        $pk->y = $this->y;
        $pk->z = $this->z;
        $pk->data = $this->data;
        return $pk;
    }
}
