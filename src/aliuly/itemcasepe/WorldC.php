<?php
declare(strict_types=1);

namespace aliuly\itemcasepe;

use pocketmine\entity\Entity;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\world\World;
use pocketmine\event\entity\EntityEvent;

/**
 * @phpstan-extends EntityEvent<Entity>
 */
class EntityWorldChangeEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;
	
	/** @var World */
	private $originWorld;
	/** @var World */
	private $targetWorld;

	public function __construct(Entity $entity, World $originWorld, World $targetWorld){
		$this->entity = $entity;
		$this->originWorld = $originWorld;
		$this->targetWorld = $targetWorld;
	}

	public function getOrigin() : World{
		return $this->originWorld;
	}

	public function getTarget() : World{
		return $this->targetWorld;
	}
}
