<?php


namespace Kibo\Phast\Cache\File;


use Kibo\Phast\Common\ObjectifiedFunctions;

class DiskCleanup extends ProbabilisticExecutor {

    /**
     * @var integer
     */
    private $maxSize;

    /**
     * @var float
     */
    private $portionToFree;

    public function __construct(array $config, ObjectifiedFunctions $functions = null) {
        $this->maxSize = $config['diskCleanup']['maxSize'];
        $this->probability = $config['diskCleanup']['probability'];
        $this->portionToFree = $config['diskCleanup']['portionToFree'];
        parent::__construct($config, $functions);
    }

    protected function execute() {
        list ($usedSpace, $files) = $this->calculateUsedSpace();
        $neededSpace = round($this->portionToFree * $this->maxSize);
        $bytesToDelete = $usedSpace - $this->maxSize + $neededSpace;
        $deletedBytes = 0;
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $deletedBytes += $file->getSize();
            @unlink($file->getRealPath());
            if ($deletedBytes >= $bytesToDelete) {
                break;
            }
        }
    }

    private function calculateUsedSpace() {
        $size = 0;
        $files = [];
        /** @var \SplFileInfo $file */
        foreach ($this->getCacheFiles($this->cacheRoot) as $file) {
            $size += $file->getSize();
            $files[] = $file;
        }
        return [$size, $files];
    }

}
