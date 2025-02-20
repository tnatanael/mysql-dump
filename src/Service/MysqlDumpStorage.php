<?php

namespace Lexuses\MysqlDump\Service;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;

class MysqlDumpStorage
{
    /**
     * @var MysqlDumpApp
     */
    private $dump;

    protected $storageName;
    protected $storage;
    protected $separator;
    protected $system;
    protected $dumpDir;
    protected $path;
    protected $extension;

    public function __construct($storageName)
    {
        $this->storageName = $storageName;
        $this->storage = $this->getStorage($storageName);
        $this->system = Config::get('filesystems.disks.' . $this->storage['disk']);
        $this->extension = Config::get('mysql_dump.compress') ? '.sql.gz' : '.sql';

        if(!$this->system)
            throw new \Exception('Disk not found in filesystems.php');

        $this->separator = Config::get('mysql_dump.separator');
        $this->dumpDir = date(Config::get('mysql_dump.dir_name'));
        $this->path =
            $this->storage['path'] .
            $this->separator .
            $this->dumpDir;
    }

    public function setCreator(MysqlDumpApp $creator)
    {
        $this->dump = $creator;
    }

    /**
     * Return storage from config
     * @param $storageName
     * @return mixed
     */
    protected function getStorage($storageName)
    {
        return Config::get('mysql_dump.storage.' . $storageName);
    }

    /**
     * Return path to dump file
     * @param $dumpName
     * @return string
     */
    protected function getPathToDump($dumpName)
    {
        if(isset($this->system['root']))
            return implode($this->separator, [
                $this->system['root'],
                $this->path,
                $dumpName . $this->extension
            ]);

        return $this->path . $this->separator . $dumpName . $this->extension;
    }

    /**
     * Copy dump from temp to destination folder
     * @throws \Exception
     */
    public function makeDump()
    {
        $dumpName = $this->dump->getName();
        $model = new MysqlDumpModel(
            $this->storage['disk'],
            $this->getPathToDump($dumpName)
        );

        Storage::disk($this->storage['disk'])->makeDirectory($this->path, 0755, true);

        $function = $this->system['driver'] == 'local' ? 'copy' : 'upload';

        return $model->$function($this->dump->getPath());
    }

    /**
     * Return dump list of the storage
     * @return Collection
     */
    public function getDumpList()
    {
        $disk = $this->storage['disk'];

        $files = new Collection(
            Storage::disk($disk)->allFiles( $this->storage['path'] )
        );
        return $files
            ->map(function($path) use ($disk){
                return new MysqlDumpModel($disk, $path);
            })
            ->sortByDesc(function($model){
                return $model->getLastModified();
            });
    }


    public function checkMaxDumps()
    {
        // Get all dumps from storage
        $dumps = $this->getDumpList();
        $now = Carbon::now('UTC');
        
        // Step 1: Day rule - Keep only most recent backup per day
        $byDay = $dumps->groupBy(function(MysqlDumpModel $dump) {
            return Carbon::createFromTimestamp($dump->getLastModified(), 'UTC')->format('Y-m-d');
        });
        
        $toKeep = new Collection();
        
        foreach ($byDay as $dayDumps) {
            // Keep most recent backup for each day
            $toKeep->push($dayDumps->sortByDesc(function($dump) {
                return $dump->getLastModified();
            })->first());
        }
        
        // Step 2: Week rule - Keep backups for last 7 days
        $weekCutoff = $now->copy()->subDays(7);
        $weekBackups = $toKeep->filter(function($dump) use ($weekCutoff) {
            return Carbon::createFromTimestamp($dump->getLastModified(), 'UTC')->greaterThanOrEqualTo($weekCutoff);
        });
        
        // Step 3: Month rule - Keep 1 backup per month for last 12 months
        $monthCutoff = $now->copy()->subMonths(12);
        $byMonth = $toKeep->filter(function($dump) use ($weekCutoff, $monthCutoff) {
            $dumpDate = Carbon::createFromTimestamp($dump->getLastModified(), 'UTC');
            return $dumpDate->lessThan($weekCutoff) && $dumpDate->greaterThanOrEqualTo($monthCutoff);
        })->groupBy(function($dump) {
            return Carbon::createFromTimestamp($dump->getLastModified(), 'UTC')->format('Y-m');
        });
        
        $monthBackups = new Collection();
        foreach ($byMonth as $monthDumps) {
            // Keep the most recent backup for each month
            $monthBackups->push($monthDumps->sortByDesc(function($dump) {
                return $dump->getLastModified();
            })->first());
        }
        
        // Step 4: Year rule - Keep 1 backup per year for anything older
        $byYear = $toKeep->filter(function($dump) use ($monthCutoff) {
            return Carbon::createFromTimestamp($dump->getLastModified(), 'UTC')->lessThan($monthCutoff);
        })->groupBy(function($dump) {
            return Carbon::createFromTimestamp($dump->getLastModified(), 'UTC')->format('Y');
        });
        
        $yearBackups = new Collection();
        foreach ($byYear as $yearDumps) {
            // Keep the most recent backup for each year
            $yearBackups->push($yearDumps->sortByDesc(function($dump) {
                return $dump->getLastModified();
            })->first());
        }
        
        // Combine all backups to keep
        $finalKeepList = $weekBackups
            ->concat($monthBackups)
            ->concat($yearBackups)
            ->unique(function($dump) {
                return $dump->getPath();
            });
            
        // Delete backups not in the keep list
        $dumps->each(function($dump) use ($finalKeepList) {
            if (!$finalKeepList->contains(function($keep) use ($dump) {
                return $keep->getPath() === $dump->getPath();
            })) {
                $dump->delete();
            }
        });

        // Clean up empty folders after deleting dumps
        $this->cleanEmptyFolders();
    }

    /**
     * Remove empty folders in the storage path
     */
    protected function cleanEmptyFolders()
    {
        $disk = Storage::disk($this->storage['disk']);
        $basePath = $this->storage['path'];
        
        // Get all directories
        $directories = $disk->allDirectories($basePath);
        
        // Sort directories by depth (deepest first)
        usort($directories, function($a, $b) {
            return substr_count($b, '/') - substr_count($a, '/');
        });
        
        foreach ($directories as $directory) {
            // If directory has no files and no subdirectories
            if (empty($disk->files($directory)) && empty($disk->directories($directory))) {
                $disk->deleteDirectory($directory);
            }
        }
    }

    public function countBy(Collection $dumps, $period)
    {
        if($period == 'total')
            return $dumps;

        $now = Carbon::now();

        if(!isset($now->$period)){
            throw new \Exception('Period does not exists. Please check Carbon docs: http://carbon.nesbot.com/docs/#api-getters');
        }

        return $dumps
            ->filter(function($model) use ($period, $now){
                return $model->isInPeriod($period, $now);
            });
    }
}