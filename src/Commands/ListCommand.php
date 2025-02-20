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
        $list = $storage->getDumpList()
            ->groupBy(function(MysqlDumpModel $dump){
                $date = Carbon::createFromTimestamp($dump->getLastModified());
                return $date->year;
            })
            ->mapWithKeys(function($dumps, $year){
                return [$year => $dumps->groupBy(function(MysqlDumpModel $dump){
                    $date = Carbon::createFromTimestamp($dump->getLastModified());
                    return $date->month;
                })->mapWithKeys(function($dumps, $month){
                    return [$month => $dumps->groupBy(function(MysqlDumpModel $dump){
                        $date = Carbon::createFromTimestamp($dump->getLastModified());
                        return $date->day;
                    })];
                })];
            });

        $this->showList($list, 0, 0);
    }

    public function showList($array, $tabTimes, $periodIndex)
    {
        $periods =  ['year', 'month', 'day', 'list'];
        $period = $periods[$periodIndex];
        $periodIndex++;
        $tabText = str_repeat(' ', $tabTimes);

        foreach ($array as $key => $dump){
            if($dump instanceof MysqlDumpModel){
                $this->info(str_repeat(' ', $tabTimes). ($key+1) . ') ' . $dump->getName());
                continue;
            }

            /** @var MysqlDumpModel $firstDump */
            $firstDump = $dump->first();
            $date = Carbon::createFromTimestamp($firstDump->getLastModified());
            $title = match($period) {
                'year' => $date->format('Y'),
                'month' => $date->format('F Y'),
                'day' => $date->format('F jS'),
            };

            $this->line($tabText."-- $title --".$tabText);
            if($dump instanceof Collection){
                $this->showList($dump, $tabTimes+6, $periodIndex);
            }
        }
    }
}