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

        // Get all periods and its values from config
        $periods = new Collection(Config::get('mysql_dump.max_dumps'));
        // Filter periods that has value more than zero
        $periods->filter(function($value){
            return $value;
        })->each(function($value, $period) use ($dumps){
            // Count dumps by period
            $filteredDumps = $this->countBy($dumps, $period);

            // If count of dumps more than value in config
            if($filteredDumps->count() > $value){
                // Take from filtered dumps the oldest dump
                $oldestDump = $filteredDumps->sortBy(function($model){
                    return $model->getLastModified();
                })->first();

                // Delete dump
                /** @var MysqlDumpModel $oldestDump */
                $oldestDump->delete();
            }
        });
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