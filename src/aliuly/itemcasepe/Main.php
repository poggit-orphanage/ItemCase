<?php

namespace aliuly\itemcasepe;

use pocketmine\plugin\PluginBase;
use pocketmine\command\CommandExecutor;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\Player;
use pocketmine\event\Listener;

use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\math\Vector3;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\network\mcpe\protocol\AddItemEntityPacket;
use pocketmine\network\mcpe\protocol\RemoveEntityPacket;
use pocketmine\network\mcpe\protocol\MoveEntityPacket;
use pocketmine\level\Level;
use pocketmine\item\Item;
use pocketmine\event\level\LevelLoadEvent;
use pocketmine\event\level\LevelUnloadEvent;
use pocketmine\utils\Config;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ProtocolInfo as ProtocolInfo;
use pocketmine\utils\TextFormat;
use pocketmine\entity\Item as ItemEntity;

class Main extends PluginBase implements CommandExecutor, Listener {
    protected $cases = [];
    protected $touches = [];
    protected $places = [];
    protected $classic = true;

    // Access and other permission related checks
    private function access(CommandSender $sender, $permission) {
        if($sender->hasPermission($permission)) return true;
        $sender->sendMessage(TextFormat::RED . "You do not have permission to do that.");
        return false;
    }

    private function inGame(CommandSender $sender, $msg = true) {
        if($sender instanceof Player) return true;
        if($msg) $sender->sendMessage(TextFormat::RED . "You can only use this command in-game");
        return false;
    }

    // Standard call-backs
    public function onEnable() {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        // Check pre-loaded worlds
        foreach($this->getServer()->getLevels() as $l) {
            $this->loadCfg($l);
        }
        if(!is_dir($this->getDataFolder())) mkdir($this->getDataFolder());
        $defaults = [
            "version" => $this->getDescription()->getVersion(),
            "settings" => [
                "classic" => true,
            ],
        ];
        $cf = (new Config($this->getDataFolder() . "config.yml",
            Config::YAML, $defaults))->getAll();
        $this->classic = $cf["settings"]["classic"];
        if(!$this->classic) $this->getLogger()->info(TextFormat::YELLOW . "ItemCasePE in NEW WAVE mode");
    }

    public function onCommand(CommandSender $sender, Command $cmd, $label, array $args) {
        switch($cmd->getName()) {
            case "itemcase":
                if(!count($args)) return $this->cmdAdd($sender);
                $scmd = strtolower(array_shift($args));
                switch($scmd) {
                    case "add":
                        return $this->cmdAdd($sender);
                    case "cancel":
                        return $this->cmdCancelAdd($sender);
                    case "respawn":
                        return $this->cmdRespawn($sender);
                    case "reset":
                    case "list":
                        $sender->sendMessage(TextFormat::RED . "Not implemented yet!");
                        return false;
                }
        }
        return false;
    }

    // Command implementations

    private function cmdCancelAdd(CommandSender $c) {
        if(!$this->inGame($c)) return true;
        if(!isset($this->places[$c->getName()])) {
            unset($this->places[$c->getName()]);
        }
        if(!isset($this->touches[$c->getName()])) {
            $c->sendMessage(TextFormat::RED . "NOT adding an ItemCase");
            return true;
        }
        unset($this->touches[$c->getName()]);
        $c->sendMessage(TextFormat::GREEN . "Add ItemCase CANCELLED");
        return true;
    }

    private function cmdAdd(CommandSender $c) {
        if(!$this->inGame($c)) return true;
        $c->sendMessage("Tap on the target block while holding an item");
        $this->touches[$c->getName()] = time();
        if(!isset($this->places[$c->getName()])) {
            unset($this->places[$c->getName()]);
        }
        return true;
    }

    private function cmdRespawn(CommandSender $c) {
        $players = $this->getServer()->getOnlinePlayers();
        foreach($this->getServer()->getLevels() as $lv) {
            $world = $lv->getName();
            foreach(array_keys($this->cases[$world]) as $cid) {
                $this->rmItemCase($lv, $cid, $players);
            }
        }
        foreach($this->getServer()->getLevels() as $lv) {
            $world = $lv->getName();
            foreach(array_keys($this->cases[$world]) as $cid) {
                $this->sndItemCase($lv, $cid, $players);
            }
        }
        return true;
    }
    
    ////////////////////////////////////////////////////////////////////////
    //
    // Place/Remove ItemCases
    //
    ////////////////////////////////////////////////////////////////////////
    
    private function rmItemCase(Level $level, $cid, array $players) {
        //echo __METHOD__.",".__LINE__."\n";
        $world = $level->getName();
        //echo "world=$world cid=$cid\n";
        // No EID assigned, it has not been spawned yet!
        if(!isset($this->cases[$world][$cid]["eid"])) return;
        $pk = new RemoveEntityPacket();
        $pk->entityUniqueId = $this->cases[$world][$cid]["eid"];
        foreach($players as $pl) {
            $pl->directDataPacket($pk);
        }
    }

    private function sndItemCase(Level $level, $cid, array $players) {
        //echo __METHOD__.",".__LINE__."\n";
        $world = $level->getName();
        //echo "world=$world cid=$cid\n";
        $pos = explode(":", $cid);
        if(!isset($this->cases[$world][$cid]["eid"])) {
            $this->cases[$world][$cid]["eid"] = Entity::$entityCount++;
        }
        $item = Item::fromString($this->cases[$world][$cid]["item"]);
        $item->setCount($this->cases[$world][$cid]["count"]);
        $pk = new AddItemEntityPacket();
        $pk->entityRuntimeId = $this->cases[$world][$cid]["eid"];
        $pk->type = ItemEntity::NETWORK_ID;
        $pk->item = $item;
        $pk->x = $pos[0] + 0.5;
        $pk->y = (float) $pos[1];
        $pk->z = $pos[2] + 0.5;
        $pk->speedX = 0;
        $pk->speedY = 0;
        $pk->speedZ = 0;
        $pk->meta = 0;
        $pk->metadata = [Entity::DATA_FLAGS => [Entity::DATA_TYPE_LONG, 1 << Entity::DATA_FLAG_IMMOBILE]];
        foreach($players as $pl) {
            $pl->directDataPacket($pk);
        }
        //$pk = new MoveEntityPacket();
        //$pk->entities = [[$this->cases[$world][$cid]["eid"],
        //$pos[0] + 0.5,$pos[1] + 0.25,$pos[2] + 0.5,0,0]];
        //foreach ($players as $pl) {
        //$pl->directDataPacket($pk);
        //}
    }

    public function spawnPlayerCases(Player $pl, Level $level) {
        if(!isset($this->cases[$level->getName()])) return;
        foreach(array_keys($this->cases[$level->getName()]) as $cid) {
            $this->sndItemCase($level, $cid, [$pl]);
        }
    }

    public function spawnLevelItemCases(Level $level) {
        if(!isset($this->cases[$level->getName()])) return;
        $ps = $level->getPlayers();
        if(!count($ps)) {
            foreach(array_keys($this->cases[$level->getName()]) as $cid) {
                $this->sndItemCase($level, $cid, $ps);
            }
        }
    }

    public function despawnPlayerCases(Player $pl, Level $level) {
        $world = $level->getName();
        if(!isset($this->cases[$world])) return;
        foreach(array_keys($this->cases[$world]) as $cid) {
            $this->rmItemCase($level, $cid, [$pl]);
        }
    }

    public function addItemCase(Level $level, $cid, $idmeta, $count) {
        //echo __METHOD__.",".__LINE__."\n";
        $world = $level->getName();
        //echo "world=$world cid=$cid idmeta=$idmeta\n";
        if(!isset($this->cases[$world])) $this->cases[$world] = [];
        if(isset($this->cases[$world][$cid])) return false;
        $this->cases[$world][$cid] = ["item" => $idmeta, "count" => $count];
        $this->saveCfg($level);
        //echo "ADDING $cid - $idmeta,$count\n";
        $this->sndItemCase($level, $cid, $level->getPlayers());
        return true;
    }

    private function saveCfg(Level $lv) {
        $world = $lv->getName();
        $f = $lv->getProvider()->getPath() . "itemcasepe.txt";
        if(!isset($this->cases[$world]) || count($this->cases[$world]) == 0) {
            if(file_exists($f)) unlink($f);
            return;
        }
        $dat = "# ItemCasePE data \n";
        foreach($this->cases[$world] as $cid => $ii) {
            $dat .= implode(",", [$cid, $ii["item"], $ii["count"]]) . "\n";
        }
        file_put_contents($f, $dat);
    }

    private function loadCfg(Level $lv) {
        $world = $lv->getName();
        $f = $lv->getProvider()->getPath() . "itemcasepe.txt";
        $this->cases[$world] = [];
        if(!file_exists($f)) return;
        foreach(explode("\n", file_get_contents($f)) as $ln) {
            if(preg_match('/^\s*#/', $ln)) continue;
            if(($ln = trim($ln)) == "") continue;
            $v = explode(",", $ln);
            if(count($v) < 3) continue;
            $this->cases[$world][$v[0]] = ["item" => $v[1], "count" => $v[2]];
        }
    }
    
    //////////////////////////////////////////////////////////////////////
    //
    // Event handlers
    //
    //////////////////////////////////////////////////////////////////////
    //
    // Make sure configs are loaded/unloaded
    
    public function onLevelLoad(LevelLoadEvent $e) {
        $this->loadCfg($e->getLevel());
    }

    public function onLevelUnload(LevelUnloadEvent $e) {
        $world = $e->getLevel()->getName();
        if(isset($this->cases[$world])) unset($this->cases[$world]);
    }

    public function onPlayerRespawn(PlayerRespawnEvent $ev) {
        $pl = $ev->getPlayer();
        $level = $pl->getLocation()->getLevel();
        $this->spawnPlayerCases($pl, $level);
    }

    public function onSendPacket(DataPacketSendEvent $ev) {
        if($ev->getPacket()->pid() !== ProtocolInfo::FULL_CHUNK_DATA_PACKET) {
            return;
        }
        // Re-spawn as chunks get sent...
        $pl = $ev->getPlayer();
        $level = $pl->getLevel();
        if(!isset($this->cases[$level->getName()])) return;
        $chunkX = $ev->getPacket()->chunkX;
        $chunkZ = $ev->getPacket()->chunkZ;
        foreach(array_keys($this->cases[$level->getName()]) as $cid) {
            $pos = explode(":", $cid);
            if($pos[0] >> 4 == $chunkX && $pos[2] >> 4 == $chunkZ) {
                //echo "Respawn case... $cid\n"; //##DEBUG
                $this->sndItemCase($level, $cid, [$pl]);
            }
        }
    }

    public function onLevelChange(EntityLevelChangeEvent $ev) {
        if($ev->isCancelled()) return;
        $pl = $ev->getEntity();
        if(!($pl instanceof Player)) return;
        //echo $pl->getName()." Level Change\n";
        foreach($this->getServer()->getLevels() as $lv) {
            $this->despawnPlayerCases($pl, $lv);
        }
        $this->getServer()->getScheduler()->scheduleDelayedTask(new PluginCallbackTask($this, [$this, "spawnPlayerCases"], [$pl, $ev->getTarget()]), 20);
        //$this->spawnPlayerCases($pl,$ev->getTarget());
    }

    public function onPlayerInteract(PlayerInteractEvent $ev) {
        $pl = $ev->getPlayer();
        if(!isset($this->touches[$pl->getName()])) return;
        $bl = $ev->getBlock();
        if($this->classic) {
            if($bl->getID() != Block::GLASS) {
                if($bl->getID() == Block::SLAB) {
                    $bl = $bl->getSide(Vector3::SIDE_UP);
                } else {
                    $pl->sendMessage(TextFormat::RED . "You must place item cases on slabs" . PHP_EOL . "or glass blocks!");
                    //$pl->sendMessage("or glass blocks!");
                    return;
                }
            }
        } else {
            if($bl->getID() != Block::GLASS) {
                $bl = $bl->getSide(Vector3::SIDE_UP);
            }
        }
        $cid = implode(":", [$bl->getX(), $bl->getY(), $bl->getZ()]);
        $item = $pl->getInventory()->getItemInHand();
        if($item->getId() === Item::AIR) {
            $pl->sendMessage(TextFormat::RED . "You must be holding an item!");
            $ev->setCancelled();
            return;
        }
        if(!$this->addItemCase($bl->getLevel(), $cid,
            implode(":", [$item->getId(), $item->getDamage()]),
            $item->getCount())
        ) {
            $pl->sendMessage(TextFormat::RED . "There is already an ItemCase there!");
        } else {
            $pl->sendMessage(TextFormat::GREEN . "ItemCase placed!");
        }
        unset($this->touches[$pl->getName()]);
        $ev->setCancelled();
        if(is_callable([$ev->getItem(), "canBePlaced"])) {
            if($ev->getItem()->canBePlaced()) {
                $this->places[$pl->getName()] = $pl->getName();
            }
        } elseif(is_callable([$ev->getItem(), "isPlaceable"])) {
            if($ev->getItem()->canBePlaced()) {
                $this->places[$pl->getName()] = $pl->getName();
            }
        }
    }

    public function onBlockPlace(BlockPlaceEvent $ev) {
        $pl = $ev->getPlayer();
        if(!isset($this->places[$pl->getName()])) return;
        $id = $ev->getBlock()->getId();
        $ev->setCancelled();
        unset($this->places[$pl->getName()]);
    }

    public function onBlockBreak(BlockBreakEvent $ev) {
        $pl = $ev->getPlayer();
        $bl = $ev->getBlock();
        $lv = $bl->getLevel();
        $yoff = $bl->getId() != Block::GLASS ? 1 : 0;
        $cid = implode(":", [$bl->getX(), $bl->getY() + $yoff, $bl->getZ()]);
        //echo "Block break at/near $cid\n";
        if(isset($this->cases[$lv->getName()][$cid])) {
            if(!$this->access($pl, "itemcase.destroy")) {
                $ev->setCancelled();
                return;
            }
            $pl->sendMessage(TextFormat::GREEN . "Destroying ItemCase " . $cid);
            $this->rmItemCase($lv, $cid, $this->getServer()->getOnlinePlayers());
            unset($this->cases[$lv->getName()][$cid]);
            $this->saveCfg($lv);
        }
    }
}
