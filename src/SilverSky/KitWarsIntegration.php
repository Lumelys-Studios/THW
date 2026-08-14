<?php

namespace SilverSky;

use pocketmine\Player;

/**
 * 外部插件功能整合层
 *
 * 本文件把 Touhou_Wars 原本硬依赖的外部插件功能整合进本插件，使本插件
 * 可以脱离 Advanced1vs1 与 KitKB 独立运行：
 *
 *  - Advanced1vs1（1v1 匹配队列）：玩家回城时需要退出匹配队列。
 *  - KitKB（KitKbPlayer 装备清理）：玩家回城时需要清除当前 KitKB 装备。
 *
 * 整合策略：
 *  - 当 Advanced1vs1 / KitKB 已安装并启用时，本层直接调用它们，保证与原先
 *    的运行行为完全一致（玩家能真正退出 1v1 匹配队列、清除 KitKB 装备）。
 *  - 当它们未安装时，本层自动降级为插件内置逻辑：不抛错、不影响回城流程。
 *    本插件本身不创建 1v1 匹配队列，因此未安装 Advanced1vs1 时不存在需要
 *    退出的队列；本插件的 hub 指令已自行清空背包、移除效果并重置血量。
 *
 * 注意：本服务器上的 Lobby、McpeElo、DeathLightning、Debuff 等插件仍依赖
 * Advanced1vs1 与 KitKB，因此这两个插件不能从服务器移除。本层只在它们存在
 * 时协同，不存在时不影响本插件运行。
 */
class KitWarsIntegration
{

    /**
     * 玩家离开 1v1 匹配队列。
     *
     * 原逻辑：
     *   $duelplayer = AD1vs1Main::getPlayerManager()->getPlayer($sender);
     *   $queues     = AD1vs1Main::get1vs1Manager()->getQueuesManager();
     *   $queues->removeFromQueue($duelplayer, true);
     *
     * @param Player $player
     */
    public static function leaveDuelQueue(Player $player)
    {
        if(!class_exists('\\jkorn\\ad1vs1\\AD1vs1Main')){
            // Advanced1vs1 未安装：本插件不维护匹配队列，无需处理
            return;
        }

        $duelPlayer = \jkorn\ad1vs1\AD1vs1Main::getPlayerManager()->getPlayer($player);
        if($duelPlayer !== null){
            $manager = \jkorn\ad1vs1\AD1vs1Main::get1vs1Manager();
            // 退出匹配队列
            $queues = $manager->getQueuesManager();
            $queues->removeFromQueue($duelPlayer, true);
            // 若玩家正在进行决斗，应正确地结束这场决斗（removePlayerFromDuel）
            // 而不是只 removeDuel() 把决斗从列表摘除。
            // removeDuel 会造成"孤儿决斗"：对手被困在竞技场，生成的决斗场地也不会被清理，
            // 最终同样导致玩家卡死、无法再正常进入职业战争等以 gamemode 判定的玩法。
            $duel = $manager->getDuelFromPlayer($duelPlayer);
            if($duel !== null){
                $duel->removePlayerFromDuel($duelPlayer, \jkorn\ad1vs1\duels\Abstract1vs1::REASON_LEFT_SERVER);
            }
        }
    }

    /**
     * 清除玩家当前 KitKB 装备。
     *
     * 原逻辑：
     *   if($sender instanceof KitKbPlayer){
     *       $sender->clearKit();
     *   }
     *
     * @param Player $player
     */
    public static function clearPlayerKit(Player $player)
    {
        if(class_exists('\\kitkb\\Player\\KitKbPlayer') && method_exists($player, 'clearKit')){
            $player->clearKit();
        }
    }

    /**
     * 接入 PureChat 称号：重新应用玩家的前缀/名字标签。
     *
     * Touhou_Wars 在游戏中会改变玩家的名字标签（队伍颜色 + 职业），
     * 当玩家死亡、返回大厅或游戏结束时需要恢复其 PureChat 称号。
     * PureChat 未安装时回退为普通名字，不影响流程。
     *
     * @param Player $player
     */
    public static function restoreTitle(Player $player)
    {
        $pureChat = $player->getServer()->getPluginManager()->getPlugin("PureChat");
        if($pureChat !== null && method_exists($pureChat, "getNametag")){
            $player->setNameTag($pureChat->getNametag($player));
        }else{
            $player->setNameTag($player->getName());
        }
    }
}
