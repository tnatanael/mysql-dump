<?php

namespace Lexuses\MysqlDump\Service;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;

class MysqlDumpModel
{
    protected $path;
    protected $name;
    protected $timestamp;
    protected $disk;
    protected $extension;

    /**
     * MysqlDumpModel constructor.
     * @param $disk
     * @param $path
     */
    public function __construct($disk, $path)
    {
        $this->path = $path;
        $this->name = basename($path);
        $this->disk = $disk;
        $this->extension = Config::get('mysql_dump.compress') ? '.sql.gz' : '.sql';
    }

    public function getName()
    {
        return $this->name;
    }

    public function getPath()
    {
        return $this->path;
    }

    public function getLastModified()
    {
        if ($this->timestamp === null) {
            $filename = str_replace('.sql', '', $this->getName());
            $filename = str_replace('.gz', '', $filename);
            // Parse the date in UTC to match the backup creation time
            $this->timestamp = Carbon::createFromFormat('Y-m-d_H-i-s', $filename, 'UTC')->getTimestamp();
        }
        return $this->timestamp;
    }

    public function getTime()
    {
        // Create Carbon instance in UTC to match the backup creation time
        return Carbon::createFromTimestamp($this->getLastModified(), 'UTC');
    }

    public function getMeta($property = null)
    {
        $meta = json_decode(Storage::disk($this->disk)->get($this->path), true);
        if( ! $property)
            return $meta;

        return isset($meta[$property]) ? $meta[$property] : false;
    }

    public function getDriver()
    {
        return Config::get('filesystems.disks.' . $this->disk . '.driver');
    }

    /**
     * Get the filesystem instance for the current disk
     * 
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    public function getFilesystem()
    {
        return Storage::disk($this->disk);
    }

    public function isLocal()
    {
        $system = Config::get('filesystems.disks.' . $this->disk);

        return $system['driver'] == 'local';
    }

    public function copy($tmpPath)
    {
        try{
            copy($tmpPath, $this->path);
        } catch (\Exception $e){
            throw new \Exception('Error on copy tmp dump to destination folder');
        }

        return $this->path;
    }

    public function upload($tmpPath)
    {
        Storage::disk($this->disk)
            ->putFileAs(
                str_replace($this->name, '', $this->path),
                new File($tmpPath),
                $this->name
            );
    }

    public function download($tempFolder, $readLength = 1024 * 1024, $callbackDownload = null)
    {
        $separator = Config::get('mysql_dump.separator');

        if($this->isLocal()) {
            return Storage::disk($this->disk)->path($this->path);
        }

        //If dump in cloud lets download it
        $filesystem = $this->getFilesystem();
        $dumpName = basename($this->path);
        $path = $tempFolder.$separator.$dumpName;
        @unlink($path);

        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $tempFile = fopen($path, 'ab');

        $handle = $filesystem->readStream($this->getPath());
        while (($buffer = fgets($handle, $readLength)) !== false) {
            fwrite($tempFile, $buffer);
            if($callbackDownload AND $callbackDownload instanceof Closure) {
                $callbackDownload($handle);
            }
        }

        fclose($handle);
        fclose($tempFile);

        return $path;
    }

    public function delete()
    {
        Storage::disk($this->disk)->delete($this->path);
    }

    public function isInPeriod($period, $now)
    {
        if(!$this->timestamp)
            $this->getLastModified();

        $time = Carbon::createFromTimestamp($this->timestamp);

        $availablePeriods = [
            'second', 'minute', 'hour', 'day', 'weekOfMonth', 'month', 'year'
        ];

        $periodIndex = array_search($period, $availablePeriods);
        if($periodIndex === false){
            throw new \Exception('Period does not exists. Please check Carbon docs: http://carbon.nesbot.com/docs/#api-getters');
        }

        while(isset($availablePeriods[$periodIndex])){
            $period = $availablePeriods[$periodIndex];
            if($time->$period != $now->$period)
                return false;

            $periodIndex++;
        }

        return true;
    }
}