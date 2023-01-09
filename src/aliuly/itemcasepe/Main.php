<?php
declare(strict_types=1);
namespace aliuly\itemcasepe;

use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\event\world\WorldUnloadEvent;
use pocketmine\item\StringToItemParser;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\AddItemActorPacket;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;

class Main extends PluginBase implements Listener {

	/** @var array[][] $cases */
    protected array $cases = [];
	/** @var int[] $touches */
    protected array $touches = [];
	/** @var string[] $places */
    protected array $places = [];
    protected bool $classic = true;

    // Access and other permission related checks
    private function access(CommandSender $sender): bool {
        if ($sender->hasPermission("itemcase.destroy")) return true;
        $sender->sendMessage("You do not have permission to do that.");
        return false;
    }

    private function inGame(CommandSender $sender): bool {
        if ($sender instanceof Player) return true;

        $sender->sendMessage("You can only use this command in-game");
        return false;
    }

    // Standard call-backs
    public function onEnable() : void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        // Check pre-loaded worlds
        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
            $this->loadCfg($world);
        }
        if (!is_dir($this->getDataFolder())) mkdir($this->getDataFolder());
        $defaults = [
            "version" => $this->getDescription()->getVersion(),
            "settings" => [
                "classic" => true,
            ],
        ];
        $cf = (new Config($this->getDataFolder() . "config.yml",
            Config::YAML, $defaults))->getAll();
        $this->classic = $cf["settings"]["classic"];
        if (!$this->classic) $this->getLogger()->info(TextFormat::YELLOW . "ItemCasePE in NEW WAVE mode");
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args): bool {
		if (count($args) < 1)
			return $this->cmdAdd($sender);
		return match(strtolower(array_shift($args))) {
			"add" => $this->cmdAdd($sender),
			"cancel" => $this->cmdCancelAdd($sender),
			"respawn" => $this->cmdRespawn(),
			default => false,
		};
    }

    // Command implementations

    private function cmdCancelAdd(CommandSender $c): bool {
        if (!$this->inGame($c)) return true;
        if (!isset($this->places[$c->getName()])) {
            unset($this->places[$c->getName()]);
        }
        if (!isset($this->touches[$c->getName()])) {
            $c->sendMessage("NOT adding an ItemCase");
            return true;
        }
        unset($this->touches[$c->getName()]);
        $c->sendMessage("Add ItemCase CANCELLED");
        return true;
    }

    private function cmdAdd(CommandSender $c): bool {
        if (!$this->inGame($c)) return true;
        $c->sendMessage("Tap on the target block while holding an item");
        $this->touches[$c->getName()] = time();
        if (!isset($this->places[$c->getName()])) {
            unset($this->places[$c->getName()]);
        }
        return true;
    }

    private function cmdRespawn(): bool {
        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
            $worldName = $world->getFolderName();
			$players = $world->getPlayers();
            foreach (array_keys($this->cases[$worldName]) as $cid) {
                $this->rmItemCase($world, $cid, $players);
            }
        }
        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
			$worldName = $world->getFolderName();
            $players = $world->getPlayers();
            foreach (array_keys($this->cases[$worldName]) as $cid) {
                $this->sndItemCase($world, $cid, $players);
            }
        }
        return true;
    }
    ////////////////////////////////////////////////////////////////////////
    //
    // Place/Remove ItemCases
    //
    ////////////////////////////////////////////////////////////////////////
    private function rmItemCase(World $world, $cid, array $players) {
        //echo __METHOD__.",".__LINE__."\n";
        $worldName = $world->getFolderName();
        //echo "world=$world cid=$cid\n";
        // No EID assigned, it has not been spawned yet!
        if (!isset($this->cases[$worldName][$cid]["eid"])) return;

		$pk = RemoveActorPacket::create($this->cases[$worldName][$cid]["eid"]);
        foreach ($players as $pl) {
            $pl->directDataPacket($pk);
        }
    }

	/**
	 * @param World     $world
	 * @param string    $cid
	 * @param Player[]  $players
	 *
	 * @return void
	 */
    private function sndItemCase(World $world, string $cid, array $players) : void {
        //echo __METHOD__.",".__LINE__."\n";
		$worldName = $world->getFolderName();
        //echo "world=$world cid=$cid\n";
        $pos = explode(":", $cid);
        if (!isset($this->cases[$worldName][$cid]["eid"])) {
            $this->cases[$worldName][$cid]["eid"] = Entity::nextRuntimeId();
        }
        $item = StringToItemParser::getInstance()->parse($this->cases[$worldName][$cid]["item"]);
        $item->setCount($this->cases[$worldName][$cid]["count"]);
		$collection = new EntityMetadataCollection();
		$collection->setGenericFlag(EntityMetadataFlags::IMMOBILE, true);
		$pk = AddItemActorPacket::create(
			$this->cases[$worldName][$cid]["eid"],
			$this->cases[$worldName][$cid]["eid"],
			ItemStackWrapper::legacy(TypeConverter::getInstance()->coreItemStackToNet($item)),
			new Vector3((float)$pos[0] + 0.5, (float)$pos[1] + 0.25, (float)$pos[2] + 0.5),
			new Vector3(0, 0, 0),
			$collection->getAll(),
			false
		);
		$world->getServer()->broadcastPackets($players, [$pk]);
        //$pk = new MoveEntityPacket();
        //$pk->entities = [[$this->cases[$world][$cid]["eid"],
        //$pos[0] + 0.5,$pos[1] + 0.25,$pos[2] + 0.5,0,0]];
        //foreach ($players as $pl) {
        //$pl->directDataPacket($pk);
        //}
    }

    public function spawnPlayerCases(Player $pl, World $world) {
        if (!isset($this->cases[$world->getFolderName()])) return;
        foreach (array_keys($this->cases[$world->getFolderName()]) as $cid) {
            $this->sndItemCase($world, $cid, [$pl]);
        }
    }

    public function spawnLevelItemCases(World $world) {
        if (!isset($this->cases[$world->getFolderName()])) return;
        $ps = $world->getPlayers();
        if (!count($ps)) {
            foreach (array_keys($this->cases[$world->getFolderName()]) as $cid) {
                $this->sndItemCase($world, $cid, $ps);
            }
        }
    }

    public function despawnPlayerCases(Player $pl, World $world) {
		$worldName = $world->getFolderName();
        if (!isset($this->cases[$worldName])) return;
        foreach (array_keys($this->cases[$worldName]) as $cid) {
            $this->rmItemCase($world, $cid, [$pl]);
        }
    }

    public function addItemCase(World $world, string $cid, string $idmeta, int $count): bool {
        //echo __METHOD__.",".__LINE__."\n";
		$worldName = $world->getFolderName();
        //echo "world=$world cid=$cid idmeta=$idmeta\n";
        if (!isset($this->cases[$worldName])) $this->cases[$worldName] = [];
        if (isset($this->cases[$worldName][$cid])) return false;
        $this->cases[$worldName][$cid] = ["item" => $idmeta, "count" => $count];
        $this->saveCfg($world);
        //echo "ADDING $cid - $idmeta,$count\n";
        $this->sndItemCase($world, $cid, $world->getPlayers());
        return true;
    }

    private function saveCfg(World $world) {
		$worldName = $world->getFolderName();
        $f = $world->getProvider()->getPath() . "itemcasepe.txt";
        if (!isset($this->cases[$worldName]) || count($this->cases[$worldName]) == 0) {
            if (file_exists($f)) unlink($f);
            return;
        }
        $dat = "# ItemCasePE data \n";
        foreach ($this->cases[$worldName] as $cid => $ii) {
            $dat .= implode(",", [$cid, $ii["item"], $ii["count"]]) . "\n";
        }
        file_put_contents($f, $dat);
    }

    private function loadCfg(World $world) {
        $worldName = $world->getFolderName();
        $f = $world->getProvider()->getPath() . "itemcasepe.txt";
        $this->cases[$worldName] = [];
        if (!file_exists($f)) return;
        foreach (explode("\n", file_get_contents($f)) as $ln) {
            if (preg_match('/^\s*#/', $ln)) continue;
            if (($ln = trim($ln)) == "") continue;
            $v = explode(",", $ln);
            if (count($v) < 3) continue;
            $this->cases[$worldName][$v[0]] = ["item" => $v[1], "count" => $v[2]];
        }
    }
    //////////////////////////////////////////////////////////////////////
    //
    // Event handlers
    //
    //////////////////////////////////////////////////////////////////////
    //
    // Make sure configs are loaded/unloaded
    public function onWorldLoad(WorldLoadEvent $e) {
        $this->loadCfg($e->getWorld());
    }

    public function onWorldUnload(WorldUnloadEvent $e) {
        $world = $e->getWorld()->getFolderName();
        if (isset($this->cases[$world])) unset($this->cases[$world]);
    }

    public function onPlayerJoin(PlayerJoinEvent $ev) {
        $pl = $ev->getPlayer();
        $world = $pl->getLocation()->getWorld();
        $this->spawnPlayerCases($pl, $world);
    }

    public function onPlayerRespawn(PlayerRespawnEvent $ev) {
        $pl = $ev->getPlayer();
        $world = $pl->getLocation()->getWorld();
        $this->spawnPlayerCases($pl, $world);
    }

    public function onSendPacket(DataPacketSendEvent $ev) {
		foreach($ev->getPackets() as $packet) {
			if (!$packet instanceof LevelChunkPacket) {
				return;
			}
			// Re-spawn as chunks get sent...
			foreach($ev->getTargets() as $target) {
				$world = $target->getPlayer()->getWorld();
				if (!isset($this->cases[$world->getFolderName()])) return;

				$chunkPos = $packet->getChunkPosition();
				foreach (array_keys($this->cases[$world->getFolderName()]) as $cid) {
					$pos = explode(":", $cid);
					if ((int)$pos[0] >> 4 == $chunkPos->getX() && ((int)$pos[2]) >> 4 == $chunkPos->getZ()) {
						//echo "Respawn case... $cid\n"; //##DEBUG
						$this->sndItemCase($world, $cid, [$target->getPlayer()]);
					}
				}
			}
		}
    }

    public function onWorldChange(EntityTeleportEvent $ev) {
        if ($ev->getTo()->getWorld() === $ev->getFrom()->getWorld()) return;
        $pl = $ev->getEntity();
        if (!($pl instanceof Player)) return;
        //echo $pl->getName()." Level Change\n";
        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
            $this->despawnPlayerCases($pl, $world);
        }
		$target = $ev->getTo()->getWorld();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(\Closure::fromCallable(function() use($pl, $target): void{ $this->spawnPlayerCases($pl, $target); })), 20);
        //$this->spawnPlayerCases($pl,$ev->getTarget());
    }

    public function onPlayerInteract(PlayerInteractEvent $ev) {
        $pl = $ev->getPlayer();
        if (!isset($this->touches[$pl->getName()])) return;
        $bl = $ev->getBlock();
        if ($this->classic) {
            if (!$bl->isSameType(VanillaBlocks::GLASS())) {
                if ($bl->isSameType(VanillaBlocks::STONE_SLAB())) {
                    $bl = $bl->getSide(Facing::UP);
                } else {
                    $pl->sendMessage("You must place item cases on slabs");
                    $pl->sendMessage("or glass blocks!");
                    return;
                }
            }
        } else {
            if ($bl->isSameType(VanillaBlocks::GLASS())) {
                $bl = $bl->getSide(Facing::UP);
            }
        }
		$blPos = $bl->getPosition();
        $cid = implode(":", [$blPos->getX(), $blPos->getY(), $blPos->getZ()]);
        $item = $pl->getInventory()->getItemInHand();
        if ($item->isNull()) {
            $pl->sendMessage("You must be holding an item!");
            $ev->cancel();
            return;
        }

        if (!$this->addItemCase($blPos->getWorld(), $cid,
            implode(":", [$item->getId(), $item->getMeta()]),
            $item->getCount())
        ) {
            $pl->sendMessage("There is already an ItemCase there!");
        } else {
            $pl->sendMessage("ItemCase placed!");
        }
        unset($this->touches[$pl->getName()]);
        $ev->cancel();
        if ($ev->getItem()->canBePlaced()) {
            $this->places[$pl->getName()] = $pl->getName();
        }
    }

    public function onBlockPlace(BlockPlaceEvent $ev) {
        $pl = $ev->getPlayer();
        if (!isset($this->places[$pl->getName()])) return;
        $ev->cancel();
        unset($this->places[$pl->getName()]);
    }

    public function onBlockBreak(BlockBreakEvent $ev) {
        $pl = $ev->getPlayer();
        $bl = $ev->getBlock();
		$blpos = $bl->getPosition();
        $world = $blpos->getWorld();
        $yoff = (int)!$bl->isSameType(VanillaBlocks::GLASS());
        $cid = implode(":", [$blpos->getX(), $blpos->getY() + $yoff, $blpos->getZ()]);

        //echo "Block break at/near $cid\n";
        if (isset($this->cases[$world->getFolderName()][$cid])) {
            if (!$this->access($pl)) {
                $ev->cancel();
                return;
            }
            $pl->sendMessage("Destroying ItemCase $cid");
            $this->rmItemCase($world, $cid, $this->getServer()->getOnlinePlayers());
            unset($this->cases[$world->getFolderName()][$cid]);
            $this->saveCfg($world);
        }
    }
}
