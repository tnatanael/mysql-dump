<?php

namespace Lexuses\MysqlDump\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Lexuses\MysqlDump\Service\MysqlDumpModel;
use Lexuses\MysqlDump\Service\MysqlDumpService;
use Lexuses\MysqlDump\Service\MysqlDumpStorage;

class ListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mysql-dump:list {--storage= : Storage name from config file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Return list of dumps on specified storage.';
    /**
     * @var MysqlDumpService
     */
    private $service;

    /**
     * Create a new command instance.
     *
     * @param MysqlDumpService $service
     */
    public function __construct(MysqlDumpService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Exception
     */
    public function handle()
    {
        $storage = $this->option('storage');

        if(!$storage){
            $storages = array_keys(Config::get('mysql_dump.storage'));

            $storage = count($storages) == 1
                ? $storages[0]
                : $this->choice('Choose storage:', $storages);
        }

        if(!$this->service->getStorages($storage)){
            $storages = $this->service
                ->getStorages()
                ->keys()
                ->map(function($name) {
                    return ' - '.$name;
                })
                ->implode("\n");

            return $this->error('Specified storage does not exists. Existing storages:' . "\n" . $storages);
        }

        $storage = new MysqlDumpStorage($storage);
        $dumps = $storage->getDumpList();
        
        // First level grouping - by year
        $list = $dumps->groupBy(function(MysqlDumpModel $dump) {
            return Carbon::createFromTimestamp($dump->getLastModified())->format('Y');
        })->map(function($yearGroup) {
            // Second level - by month
            return $yearGroup->groupBy(function(MysqlDumpModel $dump) {
                return Carbon::createFromTimestamp($dump->getLastModified())->format('m');
            })->map(function($monthGroup) {
                // Third level - by day
                return $monthGroup->groupBy(function(MysqlDumpModel $dump) {
                    return Carbon::createFromTimestamp($dump->getLastModified())->format('d');
                });
            });
        });

        $this->showList($list, 0, 0);
    }

    public function showList($array, $tabTimes, $periodIndex)
    {
        $periods = ['year', 'month', 'day', 'list'];
        $period = $periods[$periodIndex];
        $periodIndex++;
        $tabText = str_repeat(' ', $tabTimes);

        foreach ($array as $key => $items) {
            if ($items instanceof MysqlDumpModel) {
                $this->info($tabText . ($key+1) . ') ' . $items->getName());
                continue;
            }

            // Get the first dump from the group to determine the date
            $firstDump = $items->first();
            if ($firstDump instanceof Collection) {
                $firstDump = $firstDump->first();
                if ($firstDump instanceof Collection) {
                    $firstDump = $firstDump->first();
                }
            }

            if (!($firstDump instanceof MysqlDumpModel)) {
                continue;
            }

            $date = Carbon::createFromTimestamp($firstDump->getLastModified());
            $title = match($period) {
                'year' => $date->format('Y'),
                'month' => $date->format('F Y'),
                'day' => $date->format('F jS'),
            };

            $this->line($tabText . "-- $title --");
            
            if ($items instanceof Collection) {
                $this->showList($items, $tabTimes + 6, $periodIndex);
            }
        }
    }
}