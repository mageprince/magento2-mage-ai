<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */

namespace Mageprince\MageAI\Model\Cache;

use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Cache\TypeListInterface;

class FlushSkipper
{
    /**
     * @var int
     */
    private $depth = 0;

    /**
     * @var StateInterface
     */
    private $cacheState;

    /**
     * @var TypeListInterface
     */
    private $typeList;

    /**
     * @param StateInterface $cacheState
     * @param TypeListInterface $typeList
     */
    public function __construct(StateInterface $cacheState, TypeListInterface $typeList)
    {
        $this->cacheState = $cacheState;
        $this->typeList = $typeList;
    }

    /**
     * Run a callback while cache types are disabled in the current process.
     *
     * @param callable $callback
     * @return mixed
     */
    public function run(callable $callback)
    {
        $this->depth++;
        $previousStatuses = [];

        if ($this->depth === 1) {
            $previousStatuses = $this->disableCacheTypes();
        }

        try {
            return $callback();
        } finally {
            if ($this->depth === 1) {
                $this->restoreCacheTypes($previousStatuses);
            }
            $this->depth--;
        }
    }

    /**
     * Return whether cache clean/flush/invalidation calls should be skipped.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Disable all declared cache types without persisting cache-status changes.
     *
     * @return array<string, bool>
     */
    private function disableCacheTypes(): array
    {
        $statuses = [];
        foreach ($this->typeList->getTypes() as $type) {
            $typeCode = (string) ($type['id'] ?? '');
            if ($typeCode === '') {
                continue;
            }

            $statuses[$typeCode] = $this->cacheState->isEnabled($typeCode);
            $this->cacheState->setEnabled($typeCode, false);
        }

        return $statuses;
    }

    /**
     * Restore cache types to their previous in-process statuses.
     *
     * @param array<string, bool> $statuses
     * @return void
     */
    private function restoreCacheTypes(array $statuses): void
    {
        foreach ($statuses as $typeCode => $isEnabled) {
            $this->cacheState->setEnabled($typeCode, $isEnabled);
        }
    }
}
